<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Dialect;

use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\UpsertSql;

/**
 * SQL dialect strategy for PostgreSQL.
 *
 * Uses double-quote identifier quoting, decode() for binary literals, TRUE/FALSE
 * boolean literals, and INSERT … ON CONFLICT DO NOTHING + SELECT FOR UPDATE +
 * CASE UPDATE for the deadlock-safe bulk-upsert pattern.
 *
 * New-record INSERTs use RETURNING to retrieve the generated PK, since PDO's
 * lastInsertId() is unreliable without an explicit sequence name in PostgreSQL.
 *
 * @api
 */
final class PgsqlDialect implements SqlDialect
{
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
            return $value ? 'TRUE' : 'FALSE';
        }

        if ($col->isInteger) {
            return (string) (int) $value;
        }

        if ($col->isFloat) {
            return (string) (float) $value;
        }

        if ($col->isBinary) {
            // decode() is unambiguous regardless of bytea_output or standard_conforming_strings.
            return "decode('".\bin2hex((string) $value)."', 'hex')";
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

        // VarChar, Text, Json, Enum, etc.
        return "'".$this->escapeString((string) $value)."'";
    }

    #[\Override]
    public function insertReturningSuffix(string $quotedPkColumn): string
    {
        return "RETURNING {$quotedPkColumn}";
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
                fn (string $col): string => $this->quoteIdentifier($col).' = EXCLUDED.'.$this->quoteIdentifier($col),
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

        // Step 1: INSERT … ON CONFLICT DO NOTHING (PG equivalent of INSERT IGNORE)
        $valueSets = \array_map(
            fn (array $row) => '('.\implode(', ', $row).')',
            $rows,
        );
        $create = "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES\n    "
            .\implode(",\n    ", $valueSets)
            ."\nON CONFLICT DO NOTHING";

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
        throw new \RuntimeException(
            'PgsqlDialect::buildCreateTable() is not yet implemented. '
            .'DDL generation currently targets MySQL/MariaDB only; '
            .'see attrecord/docs/ddl-generation.md (Phase 2).',
        );
    }

    /**
     * Standard SQL string escaping with standard_conforming_strings=on (PG default since 9.1).
     * Only single quotes need escaping; backslashes are literal characters.
     */
    private function escapeString(string $value): string
    {
        return \str_replace("'", "''", $value);
    }
}
