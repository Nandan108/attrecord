<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\UpsertSql;
use PHPUnit\Framework\TestCase;

/**
 * Connection runs the dialect's connection-init statements once on construction (e.g. SQLite
 * PRAGMAs); MySQL/MariaDB and PostgreSQL declare none, so it is a no-op there.
 */
final class ConnectionInitTest extends TestCase
{
    public function testConnectionRunsDialectInitStatementsOnConstruction(): void
    {
        $session = new CapturingDbSession();
        new Connection($session, new RecordingInitDialect(['PRAGMA journal_mode=WAL', 'PRAGMA busy_timeout=5000']));

        $sqls = array_map(static fn (array $c): string => $c['sql'], $session->allCalls());
        $this->assertSame(['PRAGMA journal_mode=WAL', 'PRAGMA busy_timeout=5000'], $sqls);
    }

    public function testMysqlAndPgsqlDeclareNoInitStatements(): void
    {
        $this->assertSame([], (new MysqlDialect())->connectionInitStatements());
        $this->assertSame([], (new PgsqlDialect())->connectionInitStatements());

        // Constructing a Connection with them runs nothing (no regression for existing backends).
        $session = new CapturingDbSession();
        new Connection($session, new MysqlDialect());
        $this->assertSame([], $session->allCalls());
    }
}

/** @internal Minimal SqlDialect stub whose only meaningful method is connectionInitStatements(). */
final class RecordingInitDialect implements SqlDialect
{
    /** @param list<string> $statements */
    public function __construct(private readonly array $statements)
    {
    }

    /** @return list<string> */
    public function connectionInitStatements(): array
    {
        return $this->statements;
    }

    public function bindsBinaryAsLob(): bool
    {
        return false;
    }

    public function forUpdateClause(): string
    {
        return 'FOR UPDATE';
    }

    public function quoteIdentifier(string $name): string
    {
        return $name;
    }

    public function toLiteral(mixed $value, ColumnDefinition $col): string
    {
        return (string) $value;
    }

    public function escapeLikeWildcards(string $literal): string
    {
        return $literal;
    }

    public function likeEscapeSuffix(): string
    {
        return '';
    }

    public function insertReturningSuffix(string $quotedPkColumn): string
    {
        return $quotedPkColumn;
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function incomingRef(string $column): string
    {
        return 'VALUES('.$column.')';
    }

    /**
     * @param list<string>       $columnNames
     * @param list<list<string>> $rows
     */
    public function buildBulkInsert(string $tableName, array $columnNames, array $rows, bool $ignore = false): string
    {
        return $tableName;
    }

    public function insertIgnoreClause(array $columnNames): string
    {
        return ' ON CONFLICT DO NOTHING';
    }

    /**
     * @param list<string>           $columnNames
     * @param list<string>           $conflictCols
     * @param array<string, ?string> $updateCols
     */
    public function buildSingleUpsertSql(string $tableName, array $columnNames, array $conflictCols, array $updateCols): string
    {
        return $tableName;
    }

    /**
     * @param list<string>              $columnNames
     * @param list<list<string>>        $rows
     * @param list<string>              $updateColumns
     * @param list<array<string, bool>> $rowDirtyColumns
     */
    public function buildUpsertSql(string $tableName, string $pkColumn, array $columnNames, array $rows, array $updateColumns, array $rowDirtyColumns = []): UpsertSql
    {
        return new UpsertSql($tableName, $pkColumn, null);
    }

    public function buildCreateTable(TableSchema $schema, bool $ifNotExists = false): string
    {
        return $schema->tableName;
    }
}
