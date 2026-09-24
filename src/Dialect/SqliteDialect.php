<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Dialect;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Schema\CheckDefinition;
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
 * ## Extending this dialect
 *
 * Not final, on the same terms as {@see MysqlDialect} — see its docblock for the reasoning.
 * The extension points are {@see bindsBinaryAsLob()}, {@see supportsReturning()},
 * {@see forUpdateClause()} and {@see connectionInitStatements()}; every other method is `final`.
 * `supportsReturning()` is the one most likely to need overriding here, since RETURNING needs
 * SQLite 3.35+ and an older library will parse it as a syntax error.
 *
 * @api
 */
class SqliteDialect implements SqlDialect
{
    use UpsertJoinBuilder;

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
    final public function quoteIdentifier(string $name): string
    {
        return '"'.\str_replace('"', '""', $name).'"';
    }

    #[\Override]
    final public function toLiteral(mixed $value, ColumnDefinition $col): string
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
    final public function insertReturningSuffix(string $quotedPkColumn): string
    {
        // Use RETURNING (SQLite 3.35+) rather than lastInsertId(): for a multi-row INSERT SQLite
        // returns the *last* rowid, which would break RecordSet::upsertAll()'s first-id-based range
        // back-fill. RETURNING yields every generated id directly.
        return "RETURNING {$quotedPkColumn}";
    }

    #[\Override]
    public function supportsReturning(): bool
    {
        // SQLite 3.35+ supports RETURNING on INSERT and UPDATE.
        return true;
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
    final public function escapeLikeWildcards(string $literal): string
    {
        return \str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $literal);
    }

    #[\Override]
    final public function likeEscapeSuffix(): string
    {
        return " ESCAPE '\\'";
    }

    #[\Override]
    final public function incomingRef(string $column): string
    {
        return 'excluded.'.$this->quoteIdentifier($column);
    }

    #[\Override]
    final public function buildSingleUpsertSql(
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
            $setParts = [];
            foreach ($updateCols as $col => $expr) {
                $setParts[] = $this->quoteIdentifier($col).' = '.($expr ?? $this->incomingRef($col));
            }
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
    final public function buildBulkInsert(
        string $tableName,
        array $columnNames,
        array $rows,
        bool $ignore = false,
    ): string {
        $quotedTable = $this->quoteIdentifier($tableName);
        $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $columnNames));
        $valueSets = \array_map(
            fn (array $row) => '('.\implode(', ', $row).')',
            $rows,
        );

        return "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES\n    "
            .\implode(",\n    ", $valueSets)
            .($ignore ? $this->insertIgnoreClause($columnNames) : '');
    }

    #[\Override]
    final public function insertIgnoreClause(array $columnNames): string
    {
        // `ON CONFLICT DO NOTHING` (SQLite 3.24+) skips only a key conflict — unlike `INSERT OR
        // IGNORE`, which also silently drops NOT NULL / CHECK violations. Matches the PG form.
        return ' ON CONFLICT DO NOTHING';
    }

    /**
     * SQLite has no row locking, so the "deadlock-safe" three-step pattern degenerates: the lock
     * step is a plain ordered SELECT (no FOR UPDATE — writers are already serialized). INSERT OR
     * IGNORE + a derived-table `UPDATE … FROM` join keep the same shape as the other dialects.
     *
     * @param list<string>              $columnNames
     * @param list<list<string>>        $rows
     * @param list<string>              $updateColumns
     * @param list<array<string, bool>> $rowDirtyColumns
     */
    #[\Override]
    final public function buildUpsertSql(
        string $tableName,
        array $pkColumns,
        array $columnNames,
        array $rows,
        array $updateColumns,
        array $rowDirtyColumns = [],
    ): UpsertSql {
        $quotedTable = $this->quoteIdentifier($tableName);
        $quotedPkCols = \array_values(\array_map($this->quoteIdentifier(...), $pkColumns));
        $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $columnNames));

        $pkIndexes = \array_values(\array_map(
            static fn (string $c): int => (int) \array_search($c, $columnNames, true),
            $pkColumns,
        ));
        $keyPredicate = $this->renderKeyInList($quotedPkCols, $rows, $pkIndexes);
        $keyOrderBy = $this->renderKeyOrderBy($quotedPkCols);
        $keySelect = \implode(', ', $quotedPkCols);

        $valueSets = \array_map(
            fn (array $row) => '('.\implode(', ', $row).')',
            $rows,
        );
        $create = "INSERT OR IGNORE INTO {$quotedTable} ({$quotedCols}) VALUES\n    "
            .\implode(",\n    ", $valueSets);

        // No FOR UPDATE — SQLite serializes writers; this SELECT is just for parity of shape.
        $lock = "SELECT {$keySelect} FROM {$quotedTable}"
            ." WHERE {$keyPredicate}"
            ." ORDER BY {$keyOrderBy}";

        // Join-based UPDATE with a per-row multi-mask (see UpsertJoinBuilder). A column changed by
        // every row is written directly (u.col); a column changed by only some rows is gated by its
        // mask bit, so rows that did not change it keep their live value.
        $update = null;
        if (!empty($updateColumns)) {
            $plan = $this->computeUpsertMaskPlan($updateColumns, $rowDirtyColumns, \count($rows));
            $derived = $this->buildUpsertDerivedColumns($quotedPkCols, $columnNames, $rows, $updateColumns, $pkIndexes, $plan['maskCount'], $plan['perRowMasks']);
            $subquery = $this->renderUpsertDerivedTable($derived['columns'], $derived['valueRows']);

            $setParts = [];
            foreach ($updateColumns as $col) {
                $quotedCol = $this->quoteIdentifier($col);
                $uCol = 'u.'.$quotedCol;
                $tCol = $quotedTable.'.'.$quotedCol;
                if (isset($plan['sparseBits'][$col])) {
                    $b = $plan['sparseBits'][$col];
                    $qMask = 'u.'.$this->quoteIdentifier('_m'.\intdiv($b, 63));
                    $bitValue = 1 << ($b % 63);
                    $setParts[] = "{$quotedCol} = iif({$qMask} & {$bitValue}, {$uCol}, {$tCol})";
                } else {
                    $setParts[] = "{$quotedCol} = {$uCol}";
                }
            }
            $setClause = \implode(",\n    ", $setParts);
            $joinOn = $this->renderKeyJoin($quotedTable, 'u', $quotedPkCols);
            $update = "UPDATE {$quotedTable} SET\n    {$setClause}\nFROM (\n    {$subquery}\n    ) u\nWHERE {$joinOn}";
        }

        return new UpsertSql($create, $lock, $update);
    }

    /**
     * @param list<string>           $conflictCols
     * @param list<string>           $columnNames
     * @param list<list<string>>     $rows
     * @param array<string, ?string> $updateColumns
     */
    #[\Override]
    final public function buildBulkUpsertSql(
        string $tableName,
        array $conflictCols,
        array $columnNames,
        array $rows,
        array $updateColumns,
    ): string {
        $quotedTable = $this->quoteIdentifier($tableName);
        $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $columnNames));
        $valueSets = \array_map(
            fn (array $row) => '('.\implode(', ', $row).')',
            $rows,
        );
        $sql = "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES\n    "
            .\implode(",\n    ", $valueSets);

        // No columns to update on conflict → insert-or-ignore (targetless DO NOTHING).
        if (empty($updateColumns)) {
            return $sql.$this->insertIgnoreClause($columnNames);
        }

        $conflictTarget = \implode(', ', \array_map($this->quoteIdentifier(...), $conflictCols));
        $setParts = [];
        foreach ($updateColumns as $col => $expr) {
            $setParts[] = $this->quoteIdentifier($col).' = '.($expr ?? $this->incomingRef($col));
        }

        return $sql."\nON CONFLICT ({$conflictTarget}) DO UPDATE SET ".\implode(', ', $setParts);
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
     *
     * @param list<string> $omitForeignKeys
     */
    #[\Override]
    final public function buildCreateTable(TableSchema $schema, bool $ifNotExists = false, array $omitForeignKeys = []): string
    {
        $qt = $this->quoteIdentifier($schema->tableName);
        $createKeyword = $ifNotExists ? 'CREATE TABLE IF NOT EXISTS' : 'CREATE TABLE';

        // A single auto-increment PK is declared inline; the separate PRIMARY KEY clause is then
        // omitted (SQLite requires "INTEGER PRIMARY KEY AUTOINCREMENT" on the column itself).
        $inlinePk = $schema->columns[$schema->pk]->autoIncrement;

        $lines = [];
        foreach ($schema->columns as $col) {
            $lines[] = '  '.$this->renderColumnLine($col, $inlinePk && $col->name === $schema->pk);
        }
        if (!$inlinePk) {
            $lines[] = '  PRIMARY KEY ('.\implode(', ', \array_map($this->quoteIdentifier(...), $schema->pkColumns())).')';
        }

        foreach ($schema->uniqueKeys as $keyName => $colNames) {
            $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $colNames));
            $lines[] = '  CONSTRAINT '.$this->quoteIdentifier($keyName).' UNIQUE ('.$quotedCols.')';
        }

        foreach ($schema->checks as $check) {
            $lines[] = '  '.$this->buildCheckLine($check);
        }

        foreach ($schema->foreignKeys as $fk) {
            if (\in_array($fk->constraintName, $omitForeignKeys, true)) {
                continue; // deferred to an ALTER — see the $omitForeignKeys contract on SqlDialect
            }
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

    #[\Override]
    final public function buildColumnLine(ColumnDefinition $col): string
    {
        // Public fragment form (see SqlDialect): always the non-PK rendering — the inline
        // `INTEGER PRIMARY KEY AUTOINCREMENT` form is a CREATE-TABLE-only concern, and an
        // ALTER-added column is never the PK.
        return $this->renderColumnLine($col, false);
    }

    private function renderColumnLine(ColumnDefinition $col, bool $isInlineAutoincrementPk): string
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
            // Named, not anonymous: the name is the only stable handle on the member list once the
            // engine has rewritten the body, so it is what makes the members readable back out.
            // See ColumnDefinition::enumCheckConstraintName().
            $parts[] = 'CONSTRAINT '.$this->quoteIdentifier(ColumnDefinition::enumCheckConstraintName($col->name))
                .' CHECK ('.$this->quoteIdentifier($col->name).' IN ('.$this->renderEnumValues($col).'))';
        }

        return \implode(' ', $parts);
    }

    #[\Override]
    final public function renderColumnType(ColumnDefinition $col): string
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

    #[\Override]
    final public function buildForeignKeyLine(ForeignKeyDefinition $fk): string
    {
        return 'CONSTRAINT '.$this->quoteIdentifier($fk->constraintName)
            .' FOREIGN KEY ('.\implode(', ', \array_map($this->quoteIdentifier(...), $fk->localColumns)).')'
            .' REFERENCES '.$this->quoteIdentifier($fk->targetTableName())
            .' ('.\implode(', ', \array_map($this->quoteIdentifier(...), $fk->targetColumnNames())).')'
            .' ON DELETE '.$fk->onDelete->value
            .' ON UPDATE '.$fk->onUpdate->value;
    }

    #[\Override]
    final public function buildCheckLine(CheckDefinition $check): string
    {
        return 'CONSTRAINT '.$this->quoteIdentifier($check->constraintName)
            .' CHECK ('.$check->expression.')';
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
