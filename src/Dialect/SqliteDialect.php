<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Dialect;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\UpsertSql;

/**
 * SQL dialect strategy for SQLite.
 *
 * SQLite is a single-writer engine with dynamic type affinity: it serializes writers at the
 * database level (no row locks, so `forUpdateClause()` is empty) and stores values by affinity
 * (INTEGER / TEXT / REAL / BLOB / NUMERIC). Identifiers are double-quoted; binary literals use
 * `X'hex'`; booleans are `1`/`0`; `INSERT … ON CONFLICT DO UPDATE` handles upserts.
 *
 * On connection open it applies WAL journal mode, a busy timeout, and foreign-key enforcement
 * (all configurable) — see {@see connectionInitStatements()}. Generated PKs are read back via
 * RETURNING (SQLite 3.35+), since lastInsertId() reports only the last rowid of a multi-row
 * INSERT.
 *
 * @api
 */
final class SqliteDialect implements SqlDialect
{
    /**
     * @param string|null $journalMode   PRAGMA journal_mode (WAL by default; null to leave the default)
     * @param int|null    $busyTimeoutMs PRAGMA busy_timeout in milliseconds (null to leave the default)
     * @param bool        $foreignKeys   enable PRAGMA foreign_keys (off by default in SQLite)
     */
    public function __construct(
        private readonly ?string $journalMode = 'WAL',
        private readonly ?int $busyTimeoutMs = 5000,
        private readonly bool $foreignKeys = true,
    ) {
    }

    #[\Override]
    public function bindsBinaryAsLob(): bool
    {
        // Bind binary values as a LOB so they land in a BLOB column as raw bytes rather than
        // being coerced to TEXT affinity.
        return true;
    }

    #[\Override]
    public function quoteIdentifier(string $name): string
    {
        return '"'.\str_replace('"', '""', $name).'"';
    }

    #[\Override]
    public function toLiteral(mixed $value, ColumnDefinition $col): string
    {
        if (null === $value) {
            return 'NULL';
        }

        if ($col->isBool) {
            return $value ? '1' : '0';
        }

        if ($col->isInteger) {
            return (string) (int) $value;
        }

        if ($col->isFloat) {
            return (string) (float) $value;
        }

        if ($col->isBinary) {
            return "X'".\bin2hex((string) $value)."'";
        }

        if ($col->isDateTime) {
            $formatted = $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d H:i:s'.(($col->precision ?? 0) ? '.u' : ''))
                : (string) $value;

            return "'".$this->escapeString($formatted)."'";
        }

        if ($col->isDate) {
            $formatted = $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d')
                : (string) $value;

            return "'".$this->escapeString($formatted)."'";
        }

        return "'".$this->escapeString((string) $value)."'";
    }

    #[\Override]
    public function insertReturningSuffix(string $quotedPkColumn): string
    {
        // Use RETURNING (SQLite 3.35+) rather than lastInsertId(): for a multi-row INSERT SQLite
        // returns the *last* rowid, which would break RecordSet::saveAll()'s first-id-based range
        // back-fill. RETURNING yields every generated id directly.
        return "RETURNING {$quotedPkColumn}";
    }

    #[\Override]
    public function forUpdateClause(): string
    {
        // SQLite serializes writers at the database level — there is no per-row lock clause.
        return '';
    }

    /** @return list<string> */
    #[\Override]
    public function connectionInitStatements(): array
    {
        $statements = [];
        if (null !== $this->journalMode) {
            $statements[] = "PRAGMA journal_mode={$this->journalMode}";
        }
        if (null !== $this->busyTimeoutMs) {
            $statements[] = "PRAGMA busy_timeout={$this->busyTimeoutMs}";
        }
        if ($this->foreignKeys) {
            $statements[] = 'PRAGMA foreign_keys=ON';
        }

        return $statements;
    }

    #[\Override]
    public function escapeLikeWildcards(string $literal): string
    {
        return \str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $literal);
    }

    #[\Override]
    public function likeEscapeSuffix(): string
    {
        return " ESCAPE '\\'";
    }

    /**
     * @param list<string> $columnNames
     * @param list<string> $conflictCols
     * @param list<string> $updateCols
     */
    #[\Override]
    public function buildSingleUpsertSql(
        string $tableName,
        array $columnNames,
        array $conflictCols,
        array $updateCols,
    ): string {
        $qt = $this->quoteIdentifier($tableName);
        $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $columnNames));
        $placeholders = \implode(', ', \array_fill(0, \count($columnNames), '?'));
        $conflictTarget = \implode(', ', \array_map($this->quoteIdentifier(...), $conflictCols));

        $sql = "INSERT INTO {$qt} ({$quotedCols}) VALUES ({$placeholders})";

        if (!empty($updateCols)) {
            $setParts = \array_map(
                fn (string $col): string => $this->quoteIdentifier($col).' = excluded.'.$this->quoteIdentifier($col),
                $updateCols,
            );
            $sql .= " ON CONFLICT ({$conflictTarget}) DO UPDATE SET ".\implode(', ', $setParts);
        } else {
            $sql .= " ON CONFLICT ({$conflictTarget}) DO NOTHING";
        }

        return $sql;
    }

    /**
     * @param list<string>       $columnNames
     * @param list<list<string>> $rows
     */
    #[\Override]
    public function buildBulkInsert(
        string $tableName,
        array $columnNames,
        array $rows,
    ): string {
        $quotedTable = $this->quoteIdentifier($tableName);
        $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $columnNames));
        $valueSets = \array_map(
            fn (array $row) => '('.\implode(', ', $row).')',
            $rows,
        );

        return "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES\n    "
            .\implode(",\n    ", $valueSets);
    }

    /**
     * SQLite has no row locking, so the "deadlock-safe" three-step pattern degenerates: the lock
     * step is a plain ordered SELECT (no FOR UPDATE — writers are already serialized). INSERT OR
     * IGNORE + a CASE UPDATE keep the same shape as the other dialects.
     *
     * @param list<string>              $columnNames
     * @param list<list<string>>        $rows
     * @param list<string>              $updateColumns
     * @param list<array<string, bool>> $rowDirtyColumns
     */
    #[\Override]
    public function buildUpsertSql(
        string $tableName,
        string $pkColumn,
        array $columnNames,
        array $rows,
        array $updateColumns,
        array $rowDirtyColumns = [],
    ): UpsertSql {
        $quotedTable = $this->quoteIdentifier($tableName);
        $quotedPk = $this->quoteIdentifier($pkColumn);
        $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $columnNames));

        $pkIndex = (int) \array_search($pkColumn, $columnNames, true);
        $pkLiterals = \array_map(fn (array $row) => $row[$pkIndex], $rows);
        $inList = \implode(', ', $pkLiterals);

        $valueSets = \array_map(
            fn (array $row) => '('.\implode(', ', $row).')',
            $rows,
        );
        $create = "INSERT OR IGNORE INTO {$quotedTable} ({$quotedCols}) VALUES\n    "
            .\implode(",\n    ", $valueSets);

        // No FOR UPDATE — SQLite serializes writers; this SELECT is just for parity of shape.
        $lock = "SELECT {$quotedPk} FROM {$quotedTable}"
            ." WHERE {$quotedPk} IN ({$inList})"
            ." ORDER BY {$quotedPk} ASC";

        // CASE-based UPDATE, per-row dirty-scoped (see SqlDialect::buildUpsertSql()).
        $update = null;
        if (!empty($updateColumns)) {
            $setParts = [];
            foreach ($updateColumns as $col) {
                $setParts[] = $this->buildUpsertCaseSet($col, $columnNames, $rows, $pkIndex, $quotedPk, $rowDirtyColumns);
            }
            $setClause = \implode(",\n    ", $setParts);
            $update = "UPDATE {$quotedTable} SET\n    {$setClause}\nWHERE {$quotedPk} IN ({$inList})";
        }

        return new UpsertSql($create, $lock, $update);
    }

    /**
     * Build one column's `<col> = CASE <pk> … END` fragment for the upsert UPDATE step.
     *
     * Emits a `WHEN pk THEN value` only for the rows that changed the column (per $rowDirtyColumns),
     * with an `ELSE <col>` fallback so rows that did not change it keep their live value. A column
     * changed by every row — or when no dirty info is supplied — participates for all rows and needs
     * no `ELSE`.
     *
     * @param list<string>              $columnNames
     * @param list<list<string>>        $rows
     * @param list<array<string, bool>> $rowDirtyColumns
     */
    private function buildUpsertCaseSet(
        string $col,
        array $columnNames,
        array $rows,
        int $pkIndex,
        string $quotedPk,
        array $rowDirtyColumns,
    ): string {
        $quotedCol = $this->quoteIdentifier($col);
        $colIndex = (int) \array_search($col, $columnNames, true);
        $rowIndexes = \array_keys($rows);

        $dirtyIndexes = \array_values(\array_filter(
            $rowIndexes,
            static fn (int $i): bool => isset($rowDirtyColumns[$i][$col]),
        ));
        // Empty (no dirty info) or full (every row changed it) ⇒ all rows participate, no ELSE.
        $whenIndexes = ([] === $dirtyIndexes || \count($dirtyIndexes) === \count($rows))
            ? $rowIndexes
            : $dirtyIndexes;

        $whens = \array_map(
            fn (int $i): string => "WHEN {$rows[$i][$pkIndex]} THEN {$rows[$i][$colIndex]}",
            $whenIndexes,
        );
        $else = \count($whenIndexes) === \count($rows) ? '' : " ELSE {$quotedCol}";

        return "{$quotedCol} = CASE {$quotedPk} ".\implode(' ', $whens).$else.' END';
    }

    /**
     * Emit the `CREATE TABLE` (+ trailing `CREATE INDEX`) statements.
     *
     * SQLite specifics: a single auto-increment PK must be `INTEGER PRIMARY KEY AUTOINCREMENT`
     * inline on the column (and no separate PRIMARY KEY clause); types are affinities; there is
     * no COMMENT support (comments are dropped); secondary indexes are separate statements. The
     * SET type is rejected with a {@see SchemaException}; an `ON UPDATE` column clause has no
     * SQLite equivalent and is omitted. Foreign keys are enforced only when
     * `PRAGMA foreign_keys=ON` (applied by {@see connectionInitStatements()}).
     */
    #[\Override]
    public function buildCreateTable(TableSchema $schema, bool $ifNotExists = false): string
    {
        $qt = $this->quoteIdentifier($schema->tableName);
        $createKeyword = $ifNotExists ? 'CREATE TABLE IF NOT EXISTS' : 'CREATE TABLE';

        // A single auto-increment PK is declared inline; the separate PRIMARY KEY clause is then
        // omitted (SQLite requires "INTEGER PRIMARY KEY AUTOINCREMENT" on the column itself).
        $inlinePk = $schema->columns[$schema->pk]->autoIncrement;

        $lines = [];
        foreach ($schema->columns as $col) {
            $lines[] = '  '.$this->buildColumnLine($col, $inlinePk && $col->name === $schema->pk);
        }
        if (!$inlinePk) {
            $lines[] = '  PRIMARY KEY ('.$this->quoteIdentifier($schema->pk).')';
        }

        foreach ($schema->uniqueKeys as $keyName => $colNames) {
            $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $colNames));
            $lines[] = '  CONSTRAINT '.$this->quoteIdentifier($keyName).' UNIQUE ('.$quotedCols.')';
        }

        foreach ($schema->foreignKeys as $fk) {
            $lines[] = '  '.$this->buildForeignKeyLine($fk);
        }

        $statements = ["{$createKeyword} {$qt} (\n".\implode(",\n", $lines)."\n)"];

        $indexKeyword = $ifNotExists ? 'CREATE INDEX IF NOT EXISTS' : 'CREATE INDEX';
        foreach ($schema->indexes as $ixName => $colNames) {
            $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $colNames));
            $statements[] = "{$indexKeyword} ".$this->quoteIdentifier($ixName)." ON {$qt} ({$quotedCols})";
        }

        return \implode(";\n", $statements);
    }

    private function buildColumnLine(ColumnDefinition $col, bool $isInlineAutoincrementPk): string
    {
        if ($isInlineAutoincrementPk) {
            // SQLite's rowid alias: must be exactly INTEGER PRIMARY KEY (AUTOINCREMENT adds the
            // monotonic, no-reuse guarantee). No NOT NULL / DEFAULT are emitted here.
            return $this->quoteIdentifier($col->name).' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $parts = [$this->quoteIdentifier($col->name), $this->renderColumnType($col)];

        if ($col->isGenerated) {
            // SQLite supports both STORED and VIRTUAL generated columns (3.31+).
            $mode = ($col->generatedMode ?? GeneratedColumnMode::Stored)->value;
            $parts[] = 'GENERATED ALWAYS AS ('.((string) $col->generatedAs).') '.$mode;
        } else {
            if (!$col->nullable) {
                $parts[] = 'NOT NULL';
            }
            if (null !== $col->defaultExpr) {
                $parts[] = 'DEFAULT '.$col->defaultExpr;
            } elseif (null !== $col->default) {
                $parts[] = 'DEFAULT '.$this->toLiteral($col->default, $col);
            }
            // $col->onUpdate (MySQL ON UPDATE CURRENT_TIMESTAMP) has no SQLite column clause.
        }

        // Enum is stored as TEXT plus a CHECK constraint (SQLite enforces CHECK).
        if (ColumnType::Enum === $col->type) {
            $parts[] = 'CHECK ('.$this->quoteIdentifier($col->name).' IN ('.$this->renderEnumValues($col).'))';
        }

        return \implode(' ', $parts);
    }

    private function renderColumnType(ColumnDefinition $col): string
    {
        $type = $col->type;

        return match (true) {
            $col->isBool                                              => 'INTEGER',
            $col->isInteger                                           => 'INTEGER',
            ColumnType::Float === $type, ColumnType::Double === $type => 'REAL',
            ColumnType::Decimal === $type                             => 'NUMERIC',
            $col->isBinary                                            => 'BLOB',
            ColumnType::Set === $type                                 => throw new SchemaException(\sprintf(
                'SQLite has no SET type (column "%s"); model it as a join table or a text value.',
                $col->name,
            )),
            // Char / VarChar / Text family / Json / Enum, plus Date / DateTime / Timestamp
            // (stored as ISO-8601 TEXT) all take TEXT affinity.
            default => 'TEXT',
        };
    }

    private function renderEnumValues(ColumnDefinition $col): string
    {
        $values = $col->enumValues ?? [];

        return \implode(', ', \array_map($this->escapeStringLiteral(...), $values));
    }

    private function buildForeignKeyLine(ForeignKeyDefinition $fk): string
    {
        return 'CONSTRAINT '.$this->quoteIdentifier($fk->constraintName)
            .' FOREIGN KEY ('.$this->quoteIdentifier($fk->localColumn).')'
            .' REFERENCES '.$this->quoteIdentifier($fk->targetTableName())
            .' ('.$this->quoteIdentifier($fk->targetColumnName()).')'
            .' ON DELETE '.$fk->onDelete->value
            .' ON UPDATE '.$fk->onUpdate->value;
    }

    private function escapeStringLiteral(string $value): string
    {
        return "'".$this->escapeString($value)."'";
    }

    private function escapeString(string $value): string
    {
        return \str_replace("'", "''", $value);
    }
}
