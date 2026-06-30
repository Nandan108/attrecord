<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

/**
 * PostgreSQL backend base for the dual-backend integration suites.
 *
 * Mirrors {@see IntegrationTestCase}: a suite supplies its fixture Record classes via
 * {@see recordClasses()} (in FK-dependency order) and the schema is generated from those
 * classes' attributes through {@see PgsqlDialect::buildCreateTable()}. The shared per-suite
 * case traits run unchanged against this base, so the same assertions execute on PostgreSQL.
 */
abstract class PgsqlIntegrationTestCase extends TestCase
{
    protected static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $env = static fn (string $k, string $d): string => (($v = getenv($k)) !== false && '' !== $v) ? $v : $d;
        $host = $env('PGSQL_HOST', '127.0.0.1');
        $port = $env('PGSQL_PORT', '5432');
        $name = $env('PGSQL_DB', 'attrecord_test');
        $user = $env('PGSQL_USER', 'postgres');
        $pass = $env('PGSQL_PASS', 'postgres');

        try {
            static::$pdo = new \PDO(
                "pgsql:host={$host};port={$port};dbname={$name}",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException $e) {
            self::markTestSkipped(
                "PostgreSQL not available ({$e->getMessage()}).\n".
                'Start the test database with: docker compose up -d',
            );
        }

        $conn = new Connection(new PdoDbSession(static::$pdo), new PgsqlDialect());
        Record::setConnection($conn);
        TableSchema::clearCache();

        static::createSchema();
    }

    protected function setUp(): void
    {
        static::truncateTables();
    }

    /**
     * Fixture Record classes for this suite, in FK-dependency order (referenced tables first).
     *
     * @return list<class-string<Record>>
     */
    abstract protected static function recordClasses(): array;

    protected static function createSchema(): void
    {
        $dialect = Record::connection()->dialect;
        foreach (static::recordClasses() as $class) {
            static::$pdo->exec($dialect->buildCreateTable(TableSchema::fromClass($class), ifNotExists: true));
        }
    }

    protected static function truncateTables(): void
    {
        $tables = array_map(
            static fn (string $class): string => '"'.TableSchema::fromClass($class)->tableName.'"',
            static::recordClasses(),
        );
        if ([] !== $tables) {
            // CASCADE resolves FK order; RESTART IDENTITY rewinds the sequences so BIGSERIAL
            // ids restart at 1 per test (matching MySQL's TRUNCATE auto-increment reset).
            static::$pdo->exec('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
        }
    }
}
