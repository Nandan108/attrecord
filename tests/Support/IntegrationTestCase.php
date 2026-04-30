<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

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

    protected static function createSchema(): void
    {
    }

    protected static function truncateTables(): void
    {
    }
}
