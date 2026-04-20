<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Dialect;

use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\SqlDialect;

/**
 * SQL dialect strategy for MySQL / MariaDB.
 *
 * Uses backtick identifier quoting, X'hex' binary literals, and the
 * INSERT … SELECT … UNION ALL … ON DUPLICATE KEY UPDATE bulk-upsert pattern.
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

    /**
     * @param list<string>       $columnNames   Unquoted column names (ordered)
     * @param list<string>       $pkColumnNames PK column name(s)
     * @param list<list<string>> $rows          Each inner list = ordered SQL literals per column
     * @param list<string>       $updateColumns Columns to set on conflict (excludes PK)
     */
    #[\Override]
    public function buildBulkUpsert(
        string $tableName,
        array $columnNames,
        array $pkColumnNames,
        array $rows,
        array $updateColumns,
    ): string {
        $quotedTable = $this->quoteIdentifier($tableName);
        $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $columnNames));

        // First row uses "literal AS `col`" for column-name discovery in the derived table
        $firstRow = $rows[0];
        $firstParts = [];
        foreach ($columnNames as $i => $col) {
            $firstParts[] = $firstRow[$i].' AS '.$this->quoteIdentifier($col);
        }
        $unionRows = [\implode(', ', $firstParts)];
        $restRows = \array_slice($rows, 1);
        foreach ($restRows as $row) {
            $unionRows[] = \implode(', ', $row);
        }

        $selectBlock = \implode("\n                UNION ALL SELECT ", $unionRows);

        $updateParts = \array_map(
            fn (string $col) => $this->quoteIdentifier($col).' = vals.'.$this->quoteIdentifier($col),
            $updateColumns,
        );
        $onDuplicate = \implode(', ', $updateParts);

        return <<<SQL
            INSERT INTO {$quotedTable} ({$quotedCols})
            SELECT {$quotedCols} FROM (
                SELECT {$selectBlock}
            ) vals
            ON DUPLICATE KEY UPDATE {$onDuplicate}
            SQL;
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
