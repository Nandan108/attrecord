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
    public function bindsBinaryAsLob(): bool
    {
        // PDO_pgsql rejects raw bytes bound as a text parameter; binary must bind as a LOB.
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
            // A bare NULL is untyped. In `INSERT … VALUES` PG infers the type from the target
            // column, but inside a multi-row upsert's `CASE … WHEN pk THEN NULL END` there is no
            // such context, so PG defaults the branch to `text` and rejects it against a non-text
            // column (SQLSTATE 42804). Emit a typed null so the CASE result carries the column's
            // type. Autoincrement (SERIAL) columns render null only in INSERT VALUES — never in a
            // CASE branch — and SERIAL is not a castable type, so leave those bare.
            return $col->autoIncrement ? 'NULL' : 'CAST(NULL AS '.$this->renderColumnType($col).')';
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

    /**
     * Emit one or more semicolon-separated DDL statements for the table.
     *
     * Unlike MySQL, PostgreSQL cannot declare secondary indexes or column/table comments
     * inline in CREATE TABLE, so those are appended as separate `CREATE INDEX` / `COMMENT ON`
     * statements. The whole batch is safe to run in one PDO::exec() call.
     *
     * MySQL-specific declarations have no column-clause equivalent in PostgreSQL and are
     * intentionally not emitted: an `ON UPDATE` expression (requires a trigger) and the
     * engine/charset/collation table options. A VIRTUAL generated column and the SET type
     * are rejected with a {@see SchemaException}.
     */
    #[\Override]
    public function buildCreateTable(TableSchema $schema, bool $ifNotExists = false): string
    {
        $qt = $this->quoteIdentifier($schema->tableName);
        $createKeyword = $ifNotExists ? 'CREATE TABLE IF NOT EXISTS' : 'CREATE TABLE';

        $lines = [];
        foreach ($schema->columns as $col) {
            $lines[] = '  '.$this->buildColumnLine($col);
        }
        $lines[] = '  PRIMARY KEY ('.$this->quoteIdentifier($schema->pk).')';

        foreach ($schema->uniqueKeys as $keyName => $colNames) {
            $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $colNames));
            $lines[] = '  CONSTRAINT '.$this->quoteIdentifier($keyName).' UNIQUE ('.$quotedCols.')';
        }

        foreach ($schema->foreignKeys as $fk) {
            $lines[] = '  '.$this->buildForeignKeyLine($fk);
        }

        $statements = ["{$createKeyword} {$qt} (\n".\implode(",\n", $lines)."\n)"];

        // Secondary indexes — PostgreSQL declares these outside CREATE TABLE.
        $indexKeyword = $ifNotExists ? 'CREATE INDEX IF NOT EXISTS' : 'CREATE INDEX';
        foreach ($schema->indexes as $ixName => $colNames) {
            $quotedCols = \implode(', ', \array_map($this->quoteIdentifier(...), $colNames));
            $statements[] = "{$indexKeyword} ".$this->quoteIdentifier($ixName)." ON {$qt} ({$quotedCols})";
        }

        // Table + column comments — PostgreSQL has no inline COMMENT clause.
        if (null !== $schema->comment) {
            $statements[] = "COMMENT ON TABLE {$qt} IS ".$this->escapeStringLiteral($schema->comment);
        }
        foreach ($schema->columns as $col) {
            if (null !== $col->comment) {
                $statements[] = "COMMENT ON COLUMN {$qt}.".$this->quoteIdentifier($col->name)
                    .' IS '.$this->escapeStringLiteral($col->comment);
            }
        }

        return \implode(";\n", $statements);
    }

    private function buildColumnLine(ColumnDefinition $col): string
    {
        $parts = [$this->quoteIdentifier($col->name), $this->renderColumnType($col)];

        if ($col->isGenerated) {
            if (GeneratedColumnMode::Virtual === $col->generatedMode) {
                throw new SchemaException(\sprintf(
                    'PostgreSQL does not support VIRTUAL generated columns (column "%s"); use GeneratedColumnMode::Stored.',
                    $col->name,
                ));
            }
            $parts[] = 'GENERATED ALWAYS AS ('.((string) $col->generatedAs).') STORED';
        } elseif (!$col->autoIncrement) {
            // SERIAL pseudo-types imply NOT NULL and a sequence default; emit neither for them.
            if (!$col->nullable) {
                $parts[] = 'NOT NULL';
            }

            if (null !== $col->defaultExpr) {
                $parts[] = 'DEFAULT '.$col->defaultExpr;
            } elseif (null !== $col->default) {
                $parts[] = 'DEFAULT '.$this->toLiteral($col->default, $col);
            }
            // $col->onUpdate (MySQL ON UPDATE CURRENT_TIMESTAMP) has no PostgreSQL column
            // clause — it requires a trigger — and is intentionally omitted.
        }

        // Enum is stored as TEXT plus a CHECK constraint listing the allowed values.
        if (ColumnType::Enum === $col->type) {
            $parts[] = 'CHECK ('.$this->quoteIdentifier($col->name).' IN ('.$this->renderEnumValues($col).'))';
        }

        return \implode(' ', $parts);
    }

    private function renderColumnType(ColumnDefinition $col): string
    {
        // Auto-increment columns use the SERIAL pseudo-types (sequence-backed).
        if ($col->autoIncrement) {
            return match ($col->type) {
                ColumnType::BigInt, ColumnType::BigIntUnsigned => 'BIGSERIAL',
                ColumnType::TinyInt, ColumnType::TinyIntUnsigned,
                ColumnType::SmallInt, ColumnType::SmallIntUnsigned => 'SMALLSERIAL',
                default                                            => 'SERIAL',
            };
        }

        $type = $col->type;
        $precision = $col->precision ?? 0;

        return match (true) {
            ColumnType::Bool === $type => 'BOOLEAN',
            // PostgreSQL has no unsigned integers; map to the smallest type that fits.
            ColumnType::TinyInt === $type, ColumnType::TinyIntUnsigned === $type,
            ColumnType::SmallInt === $type, ColumnType::SmallIntUnsigned === $type,
            ColumnType::Year === $type                                          => 'SMALLINT',
            ColumnType::MediumInt === $type, ColumnType::MediumIntUnsigned === $type,
            ColumnType::Int === $type, ColumnType::IntUnsigned === $type         => 'INTEGER',
            ColumnType::BigInt === $type, ColumnType::BigIntUnsigned === $type   => 'BIGINT',
            ColumnType::Bit === $type                                            => null !== $col->length ? 'BIT('.$col->length.')' : 'BIT',
            ColumnType::Float === $type                                          => 'REAL',
            ColumnType::Double === $type                                         => 'DOUBLE PRECISION',
            ColumnType::Decimal === $type                                        => 'NUMERIC('.$precision.', '.((int) $col->scale).')',
            ColumnType::Char === $type                                           => 'CHAR('.((int) $col->length).')',
            ColumnType::VarChar === $type                                        => 'VARCHAR('.((int) $col->length).')',
            ColumnType::Json === $type                                           => 'JSONB',
            ColumnType::Enum === $type                                           => 'TEXT', // CHECK constraint added in buildColumnLine()
            ColumnType::Set === $type                                            => throw new SchemaException(\sprintf(
                'PostgreSQL has no SET type (column "%s"); model it as a join table or a text array.',
                $col->name,
            )),
            $col->isBinary                                                  => 'BYTEA',
            ColumnType::Date === $type                                      => 'DATE',
            ColumnType::DateTime === $type, ColumnType::Timestamp === $type => $precision ? 'TIMESTAMP('.$precision.')' : 'TIMESTAMP',
            // tinytext / text / mediumtext / longtext all collapse to TEXT.
            default => 'TEXT',
        };
    }

    private function renderEnumValues(ColumnDefinition $col): string
    {
        // enumValues non-emptiness is enforced at schema-build time for Enum/Set.
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

    /** Wrap a string in single quotes for embedding as a SQL literal (comments, enum values). */
    private function escapeStringLiteral(string $value): string
    {
        return "'".$this->escapeString($value)."'";
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
