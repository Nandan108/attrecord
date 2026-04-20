<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Strategy for dialect-specific SQL generation.
 *
 * Used only in RecordSet::saveAll() where values are embedded directly in the SQL string.
 * Single-record save() uses parameterised queries through DbSession instead.
 *
 * @api
 */
interface SqlDialect
{
    /**
     * Convert a PHP value to a SQL literal for embedding in a bulk statement.
     * Must return a properly quoted/escaped string that is safe to embed verbatim.
     */
    public function toLiteral(mixed $value, ColumnDefinition $col): string;

    /** Quote a table or column identifier (e.g. backticks for MySQL, double quotes for Postgres). */
    public function quoteIdentifier(string $name): string;

    /**
     * SQL suffix appended to a single-record INSERT to retrieve the generated PK.
     *
     * Return an empty string for MySQL/MariaDB where lastInsertId() is reliable.
     * Return "RETURNING {quotedPk}" for PostgreSQL (PDO's lastInsertId() requires an
     * explicit sequence name in PG and is therefore unreliable without it).
     *
     * @param string $quotedPkColumn Already-quoted PK column name (via quoteIdentifier())
     */
    public function insertReturningSuffix(string $quotedPkColumn): string;

    /**
     * Build a plain bulk INSERT for new records (no known PK).
     *
     * @param string             $tableName   Unquoted table name
     * @param list<string>       $columnNames Column names to insert (unquoted; PK excluded)
     * @param list<list<string>> $rows        Each inner list is ordered SQL literals per column
     */
    public function buildBulkInsert(
        string $tableName,
        array $columnNames,
        array $rows,
    ): string;

    /**
     * Build the three SQL statements for a deadlock-safe bulk upsert.
     *
     * Step 1 (create): INSERT IGNORE — inserts genuinely new rows; silently skips existing ones.
     * Step 2 (lock):   SELECT pk … ORDER BY pk ASC FOR UPDATE — acquires row locks in a
     *                  deterministic order, eliminating deadlocks from lock-order inversion.
     * Step 3 (update): CASE-based UPDATE — applies column values in a single statement.
     *                  null when $updateColumns is empty (insert-only scenario).
     *
     * All three statements must run inside the same transaction.
     *
     * @param string             $tableName     Unquoted table name
     * @param string             $pkColumn      PK column name (unquoted)
     * @param list<string>       $columnNames   All columns to write, including PK (unquoted)
     * @param list<list<string>> $rows          SQL literals per row, in $columnNames order
     * @param list<string>       $updateColumns Non-PK columns to overwrite on conflict
     */
    public function buildUpsertSql(
        string $tableName,
        string $pkColumn,
        array $columnNames,
        array $rows,
        array $updateColumns,
    ): UpsertSql;
}
