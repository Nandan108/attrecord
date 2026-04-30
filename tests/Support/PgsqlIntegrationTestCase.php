<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

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

    protected static function createSchema(): void
    {
    }

    protected static function truncateTables(): void
    {
    }
}
