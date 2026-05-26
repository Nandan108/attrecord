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
    /** Default storage engine used when a Record does not declare #[MysqlTableOptions]. */
    public const DEFAULT_ENGINE = 'InnoDB';

    /** Default charset used when a Record does not declare #[MysqlTableOptions]. */
    public const DEFAULT_CHARSET = 'utf8mb4';

    /** Default collation used when a Record does not declare #[MysqlTableOptions]. */
    public const DEFAULT_COLLATION = 'utf8mb4_unicode_ci';

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
            $formatted = $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d H:i:s')
                : (string) $value;

            return "'".$this->escapeString($formatted)."'";
        }

        if ($col->isDate) {
            $formatted = $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d')
                : (string) $value;

            return "'".$this->escapeString($formatted)."'";
        }

        // VarChar, Text, Json, Enum, etc.
        return "'".$this->escapeString((string) $value)."'";
    }

    #[\Override]
    public function insertReturningSuffix(string $quotedPkColumn): string
    {
        return '';
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

        $sql = "INSERT INTO {$qt} ({$quotedCols}) VALUES ({$placeholders})";

        if (!empty($updateCols)) {
            $setParts = \array_map(
                fn (string $col): string => $this->quoteIdentifier($col).' = VALUES('.$this->quoteIdentifier($col).')',
                $updateCols,
            );
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
     * @param list<string>       $columnNames
     * @param list<list<string>> $rows
     * @param list<string>       $updateColumns
     */
    #[\Override]
    public function buildUpsertSql(
        string $tableName,
        string $pkColumn,
        array $columnNames,
        array $rows,
        array $updateColumns,
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

        // Step 3: CASE-based UPDATE for all non-PK columns
        $update = null;
        if (!empty($updateColumns)) {
            $setParts = [];
            foreach ($updateColumns as $col) {
                $quotedCol = $this->quoteIdentifier($col);
                $colIndex = (int) \array_search($col, $columnNames, true);
                $whens = \array_map(
                    fn (array $row) => "WHEN {$row[$pkIndex]} THEN {$row[$colIndex]}",
                    $rows,
                );
                $setParts[] = "{$quotedCol} = CASE {$quotedPk} ".\implode(' ', $whens).' END';
            }
            $setClause = \implode(",\n    ", $setParts);
            $update = "UPDATE {$quotedTable} SET\n    {$setClause}\nWHERE {$quotedPk} IN ({$inList})";
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
        $sql .= ' ENGINE='.($opts?->engine ?? self::DEFAULT_ENGINE);
        $sql .= ' DEFAULT CHARSET='.($opts?->charset ?? self::DEFAULT_CHARSET);
        $sql .= ' COLLATE='.($opts?->collation ?? self::DEFAULT_COLLATION);

        if (null !== $schema->comment) {
            $sql .= " COMMENT='".$this->escapeString($schema->comment)."'";
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
            $parts[] = "COMMENT '".$this->escapeString($col->comment)."'";
        }

        return \implode(' ', $parts);
    }

    private function renderColumnType(ColumnDefinition $col): string
    {
        $type = $col->type;

        return match (true) {
            ColumnType::Bool === $type      => 'TINYINT(1)',
            ColumnType::VarChar === $type   => 'VARCHAR('.((int) $col->length).')',
            ColumnType::Char === $type      => 'CHAR('.((int) $col->length).')',
            ColumnType::VarBinary === $type => 'VARBINARY('.((int) $col->length).')',
            ColumnType::Binary === $type    => 'BINARY('.((int) $col->length).')',
            ColumnType::Bit === $type       => null !== $col->length ? 'BIT('.$col->length.')' : 'BIT',
            ColumnType::Decimal === $type   => 'DECIMAL('.((int) $col->precision).', '.((int) $col->scale).')',
            ColumnType::DateTime === $type  => null !== $col->precision ? 'DATETIME('.$col->precision.')' : 'DATETIME',
            ColumnType::Timestamp === $type => null !== $col->precision ? 'TIMESTAMP('.$col->precision.')' : 'TIMESTAMP',
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
            fn (string $v): string => "'".$this->escapeString($v)."'",
            $values,
        ));
    }

    private function buildForeignKeyLine(ForeignKeyDefinition $fk): string
    {
        $targetSchema = TableSchema::fromClass($fk->targetClass);

        return 'CONSTRAINT '.$this->quoteIdentifier($fk->constraintName)
            .' FOREIGN KEY ('.$this->quoteIdentifier($fk->localColumn).')'
            .' REFERENCES '.$this->quoteIdentifier($targetSchema->tableName)
            .' ('.$this->quoteIdentifier($targetSchema->pk).')'
            .' ON DELETE '.$fk->onDelete->value
            .' ON UPDATE '.$fk->onUpdate->value;
    }

    /**
     * Pure-PHP MySQL string escaping (no connection required).
     * Safe for all string values; binary data uses X'hex' via toLiteral() instead.
     */
    private function escapeString(string $value): string
    {
        return \str_replace(
            ['\\',  "\0",  "\n",  "\r",  "'",   '"',   "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value,
        );
    }
}
