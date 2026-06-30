<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

/**
 * MySQL/MariaDB backend base for the dual-backend integration suites.
 *
 * A suite supplies its fixture Record classes via {@see recordClasses()} (in FK-dependency
 * order); the schema is then generated from those classes' attributes through the dialect's
 * DDL producer — the same code path a consumer's install routine uses — so the integration
 * tests dogfood {@see MysqlDialect::buildCreateTable()}. The companion PostgreSQL base
 * ({@see PgsqlIntegrationTestCase}) reuses the same {@see recordClasses()} against the PG
 * dialect, so one body of test cases runs identically on both engines.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $env = static fn (string $k, string $d): string => (($v = getenv($k)) !== false && '' !== $v) ? $v : $d;
        $host = $env('DB_HOST', '127.0.0.1');
        $port = $env('DB_PORT', '3306');
        $name = $env('DB_NAME', 'attrecord_test');
        $user = $env('DB_USER', 'root');
        $pass = $env('DB_PASS', 'root');

        try {
            static::$pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException $e) {
            self::markTestSkipped(
                "MariaDB not available ({$e->getMessage()}).\n".
                'Start the test database with: docker compose up -d',
            );
        }

        $conn = new Connection(new PdoDbSession(static::$pdo), new MysqlDialect());
        Record::setConnection($conn);
        TableSchema::clearCache();

        static::createSchema();
    }

    protected function setUp(): void
    {
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        static::truncateTables();
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
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
        foreach (static::recordClasses() as $class) {
            $table = TableSchema::fromClass($class)->tableName;
            static::$pdo->exec('TRUNCATE TABLE `'.$table.'`');
        }
    }
}
