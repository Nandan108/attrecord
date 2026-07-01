<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

/**
 * SQLite backend base for the shared integration suites — a third backend alongside
 * {@see IntegrationTestCase} (MySQL) and {@see PgsqlIntegrationTestCase}.
 *
 * Uses an in-memory database (a single PDO kept for the class lifetime, so the schema persists
 * across test methods). No server/service is required. The same {@see recordClasses()} + shared
 * case traits run here as on MySQL/PostgreSQL, with schema generated from the fixtures via
 * {@see SqliteDialect::buildCreateTable()}.
 */
abstract class SqliteIntegrationTestCase extends TestCase
{
    protected static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        static::$pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $conn = new Connection(new PdoDbSession(static::$pdo), new SqliteDialect());
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
        // SQLite has no TRUNCATE. DELETE every row and reset the AUTOINCREMENT counters so ids
        // restart at 1 per test (matching MySQL TRUNCATE / PG RESTART IDENTITY). FK enforcement
        // is disabled for the wipe so table order does not matter.
        $hasSequence = (bool) static::$pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")
            ->fetchColumn();

        static::$pdo->exec('PRAGMA foreign_keys=OFF');
        foreach (static::recordClasses() as $class) {
            $table = TableSchema::fromClass($class)->tableName;
            static::$pdo->exec('DELETE FROM "'.$table.'"');
            if ($hasSequence) {
                static::$pdo->exec("DELETE FROM sqlite_sequence WHERE name = '".$table."'");
            }
        }
        static::$pdo->exec('PRAGMA foreign_keys=ON');
    }
}
