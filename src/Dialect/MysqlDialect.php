<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Dialect;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\UpsertSql;

/**
 * SQL dialect strategy for MySQL / MariaDB.
 *
 * Uses backtick identifier quoting, X'hex' binary literals, and the deadlock-safe
 * INSERT IGNORE + SELECT FOR UPDATE + CASE UPDATE bulk-upsert pattern.
 *
 * @api
 */
final class MysqlDialect implements SqlDialect
{
    use UpsertJoinBuilder;

    /** Default storage engine used when a Record does not declare #[MysqlTableOptions]. */
    public const DEFAULT_ENGINE = 'InnoDB';

    /** Default charset used when a Record does not declare #[MysqlTableOptions]. */
    public const DEFAULT_CHARSET = 'utf8mb4';

    /** Default collation used when a Record does not declare #[MysqlTableOptions]. */
    public const DEFAULT_COLLATION = 'utf8mb4_unicode_ci';

    /**
     * Instance-level table-option defaults, applied to generated `CREATE TABLE`
     * DDL when a Record carries no `#[MysqlTableOptions]`. Each is nullable; a
     * null field falls back to the corresponding `DEFAULT_*` constant.
     *
     * Resolution precedence per field: per-table `#[MysqlTableOptions]` → this
     * instance default → the `DEFAULT_*` constant. The instance default lets a
     * consumer align all generated DDL with the host database (e.g. pass the
     * live `DEFAULT_COLLATION_NAME`) instead of the library constant, without
     * annotating every Record.
     *
     * @param string|null $defaultEngine    Storage engine; null → {@see self::DEFAULT_ENGINE}
     * @param string|null $defaultCharset   Table charset; null → {@see self::DEFAULT_CHARSET}
     * @param string|null $defaultCollation Table collation; null → {@see self::DEFAULT_COLLATION}
     */
    public function __construct(
        private readonly ?string $defaultEngine = null,
        private readonly ?string $defaultCharset = null,
        private readonly ?string $defaultCollation = null,
    ) {
    }

    #[\Override]
    public function bindsBinaryAsLob(): bool
    {
        // MySQL/MariaDB bind raw bytes through an ordinary string parameter.
        return false;
    }

    #[\Override]
    public function quoteIdentifier(string $name): string
    {
        return '`'.\str_replace('`', '``', $name).'`';
    }

    #[\Override]
    public function toLiteral(mixed $value, ColumnDefinition $col): string
    {
        if (null === $value) {
            return 'NULL';
        }

        if ($col->isBool) {
            return 1 === (int) (bool) $value ? '1' : '0';
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
            // Honor fractional-seconds precision when the column declares one — without
            // this, microsecond-precision sentinel values like '9999-12-31 23:59:59.999999'
            // round-trip as '...:59.000000' and identifier-window lookups (which compare
            // the sentinel literally) silently miss rows written through this path.
            $formatted = $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d H:i:s'.(($col->precision ?? 0) ? '.u' : ''))
                : (string) $value;

            return $this->escapeStringLiteral($formatted);
        }

        if ($col->isDate) {
            $formatted = $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d')
                : (string) $value;

            return $this->escapeStringLiteral($formatted);
        }

        // VarChar, Text, Json, Enum, etc.
        return $this->escapeStringLiteral((string) $value);
    }

    #[\Override]
    public function insertReturningSuffix(string $quotedPkColumn): string
    {
        return '';
    }

    #[\Override]
    public function supportsReturning(): bool
    {
        return false;
    }

    #[\Override]
    public function forUpdateClause(): string
    {
        return 'FOR UPDATE';
    }

    /** @return list<string> */
    #[\Override]
    public function connectionInitStatements(): array
    {
        return [];
    }

    #[\Override]
    public function escapeLikeWildcards(string $literal): string
    {
        return \str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $literal);
    }

    #[\Override]
    public function likeEscapeSuffix(): string
    {
        return '';
    }

    #[\Override]
    public function incomingRef(string $column): string
    {
        // VALUES(col) is deprecated on MySQL 8.0.20+ but is the only form MariaDB supports, so it
        // stays the portable choice across the MySQL/MariaDB family this dialect serves.
        return 'VALUES('.$this->quoteIdentifier($column).')';
    }

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

        $sql = "INSERT INTO {$qt} ({$quotedCols}) VALUES ({$placeholders})";

        if (!empty($updateCols)) {
            $setParts = [];
            foreach ($updateCols as $col => $expr) {
                $setParts[] = $this->quoteIdentifier($col).' = '.($expr ?? $this->incomingRef($col));
            }
            $sql .= ' ON DUPLICATE KEY UPDATE '.\implode(', ', $setParts);
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
    public function insertIgnoreClause(array $columnNames): string
    {
        // A no-op `col = col` set ignores *only* a key conflict, unlike `INSERT IGNORE` which would
        // also downgrade truncation / NOT NULL errors to warnings. Any written column serves.
        $q = $this->quoteIdentifier($columnNames[0]);

        return " ON DUPLICATE KEY UPDATE {$q} = {$q}";
    }

    /**
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

        // Step 1: INSERT IGNORE — inserts new rows, silently skips duplicates
        $valueSets = \array_map(
            fn (array $row) => '('.\implode(', ', $row).')',
            $rows,
        );
        $create = "INSERT IGNORE INTO {$quotedTable} ({$quotedCols}) VALUES\n    "
            .\implode(",\n    ", $valueSets);

        // Step 2: SELECT pk FOR UPDATE in ascending order — deterministic lock acquisition
        $lock = "SELECT {$quotedPk} FROM {$quotedTable}"
            ." WHERE {$quotedPk} IN ({$inList})"
            ." ORDER BY {$quotedPk} ASC FOR UPDATE";

        // Step 3: join-based UPDATE with a per-row multi-mask (see UpsertJoinBuilder). A column
        // changed by every row is written directly (u.col); a column changed by only some rows is
        // gated by its mask bit, so rows that did not change it keep their live value.
        $update = null;
        if (!empty($updateColumns)) {
            $plan = $this->computeUpsertMaskPlan($updateColumns, $rowDirtyColumns, \count($rows));
            $derived = $this->buildUpsertDerivedColumns($quotedPk, $columnNames, $rows, $updateColumns, $pkIndex, $plan['maskCount'], $plan['perRowMasks']);
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
                    $setParts[] = "{$tCol} = IF({$qMask} & {$bitValue}, {$uCol}, {$tCol})";
                } else {
                    $setParts[] = "{$tCol} = {$uCol}";
                }
            }
            $setClause = \implode(",\n    ", $setParts);
            $update = "UPDATE {$quotedTable}\n    JOIN (\n    {$subquery}\n    ) u ON {$quotedTable}.{$quotedPk} = u.{$quotedPk}\nSET\n    {$setClause}";
        }

        return new UpsertSql($create, $lock, $update);
    }

    #[\Override]
    public function buildCreateTable(TableSchema $schema, bool $ifNotExists = false): string
    {
        $qt = $this->quoteIdentifier($schema->tableName);
        $createKeyword = $ifNotExists ? 'CREATE TABLE IF NOT EXISTS' : 'CREATE TABLE';

        $lines = [];

        // Columns
        foreach ($schema->columns as $col) {
            $lines[] = '  '.$this->buildColumnLine($col);
        }

        // PRIMARY KEY
        $lines[] = '  PRIMARY KEY ('.$this->quoteIdentifier($schema->pk).')';

        // UNIQUE KEYs
        foreach ($schema->uniqueKeys as $keyName => $colNames) {
            $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $colNames));
            $lines[] = '  UNIQUE KEY '.$this->quoteIdentifier($keyName).' ('.$quotedCols.')';
        }

        // Secondary indexes
        foreach ($schema->indexes as $ixName => $colNames) {
            $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $colNames));
            $lines[] = '  KEY '.$this->quoteIdentifier($ixName).' ('.$quotedCols.')';
        }

        // FOREIGN KEYs
        foreach ($schema->foreignKeys as $fk) {
            $lines[] = '  '.$this->buildForeignKeyLine($fk);
        }

        $sql = "{$createKeyword} {$qt} (\n".\implode(",\n", $lines)."\n)";

        $opts = $schema->mysqlOptions;
        $sql .= ' ENGINE='.($opts?->engine ?? $this->defaultEngine ?? self::DEFAULT_ENGINE);
        $sql .= ' DEFAULT CHARSET='.($opts?->charset ?? $this->defaultCharset ?? self::DEFAULT_CHARSET);
        $sql .= ' COLLATE='.($opts?->collation ?? $this->defaultCollation ?? self::DEFAULT_COLLATION);

        if (null !== $schema->comment) {
            $sql .= ' COMMENT='.$this->escapeStringLiteral($schema->comment);
        }

        return $sql;
    }

    private function buildColumnLine(ColumnDefinition $col): string
    {
        $parts = [$this->quoteIdentifier($col->name), $this->renderColumnType($col)];

        // Generated-column clause must appear immediately after the column type.
        // MySQL accepts an explicit NOT NULL afterwards, but MariaDB rejects it
        // (the result's nullability is inferred from the expression). For
        // portability we omit NULL/NOT NULL on generated columns entirely —
        // the expression decides.
        if ($col->isGenerated) {
            $parts[] = 'GENERATED ALWAYS AS ('.((string) $col->generatedAs).')';
            $parts[] = ($col->generatedMode ?? GeneratedColumnMode::Stored)->value;
        } elseif (!$col->nullable) {
            $parts[] = 'NOT NULL';
        }

        // Generated columns cannot carry DEFAULT, ON UPDATE, or AUTO_INCREMENT
        // (validated at schema-build time); skip those clauses entirely.
        if (!$col->isGenerated) {
            if (null !== $col->defaultExpr) {
                $parts[] = 'DEFAULT '.$col->defaultExpr;
            } elseif (null !== $col->default) {
                $parts[] = 'DEFAULT '.$this->toLiteral($col->default, $col);
            }

            if (null !== $col->onUpdate) {
                $parts[] = 'ON UPDATE '.$col->onUpdate;
            }

            if ($col->autoIncrement) {
                $parts[] = 'AUTO_INCREMENT';
            }
        }

        if (null !== $col->comment) {
            $parts[] = 'COMMENT '.$this->escapeStringLiteral($col->comment);
        }

        return \implode(' ', $parts);
    }

    private function renderColumnType(ColumnDefinition $col): string
    {
        $type = $col->type;
        $precision = $col->precision ?? 0;

        return match (true) {
            ColumnType::Bool === $type      => 'TINYINT(1)',
            ColumnType::VarChar === $type   => 'VARCHAR('.((int) $col->length).')',
            ColumnType::Char === $type      => 'CHAR('.((int) $col->length).')',
            ColumnType::VarBinary === $type => 'VARBINARY('.((int) $col->length).')',
            ColumnType::Binary === $type    => 'BINARY('.((int) $col->length).')',
            ColumnType::Bit === $type       => null !== $col->length ? 'BIT('.$col->length.')' : 'BIT',
            ColumnType::Decimal === $type   => 'DECIMAL('.$precision.', '.((int) $col->scale).')',
            ColumnType::DateTime === $type  => $precision ? 'DATETIME('.$precision.')' : 'DATETIME',
            ColumnType::Timestamp === $type => $precision ? 'TIMESTAMP('.$precision.')' : 'TIMESTAMP',
            ColumnType::Enum === $type      => 'ENUM('.$this->renderEnumValues($col).')',
            ColumnType::Set === $type       => 'SET('.$this->renderEnumValues($col).')',
            default                         => \strtoupper($type->value),
        };
    }

    private function renderEnumValues(ColumnDefinition $col): string
    {
        // enumValues non-emptiness is enforced at schema-build time for Enum/Set.
        $values = $col->enumValues ?? [];

        return \implode(', ', \array_map(
            $this->escapeStringLiteral(...),
            $values,
        ));
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

    /**
     * Pure-PHP MySQL string-literal builder (no connection required): escapes the value
     * and wraps it in single quotes — ready to splice into a SQL statement. Safe for all
     * string values; binary data uses X'hex' via toLiteral() instead.
     */
    private function escapeStringLiteral(string $value): string
    {
        return "'".\str_replace(
            ['\\',  "\0",  "\n",  "\r",  "'",   '"',   "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value,
        )."'";
    }
}
