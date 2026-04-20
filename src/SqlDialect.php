<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Strategy for dialect-specific SQL generation.
 *
 * Used only in RecordSet::saveAll() where values are embedded directly in the SQL string
 * (UNION ALL rows in the bulk-upsert pattern). Single-record save() uses parameterised
 * queries through DbSession instead.
 *
 * @api
 */
interface SqlDialect
{
    /**
     * Convert a PHP value to a SQL literal for embedding in a bulk-upsert statement.
     * Must return a properly quoted/escaped string that is safe to embed verbatim.
     */
    public function toLiteral(mixed $value, ColumnDefinition $col): string;

    /** Quote a table or column identifier (e.g. backticks for MySQL, double quotes for Postgres). */
    public function quoteIdentifier(string $name): string;

    /**
     * Build the dialect-specific bulk-upsert statement.
     *
     * @param string             $tableName     Unquoted table name
     * @param list<string>       $columnNames   Ordered list of column names (unquoted)
     * @param list<string>       $pkColumnNames Columns that form the PK / unique key (for ON CONFLICT)
     * @param list<list<string>> $rows          Each inner list is an ordered list of SQL literals
     *                                          (already produced by toLiteral())
     * @param list<string>       $updateColumns Columns to update on conflict (excludes PK columns)
     */
    public function buildBulkUpsert(
        string $tableName,
        array $columnNames,
        array $pkColumnNames,
        array $rows,
        array $updateColumns,
    ): string;
}
