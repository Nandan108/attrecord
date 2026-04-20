<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Dialect;

use Nandan108\Attrecord\Schema\ColumnDefinition;
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
