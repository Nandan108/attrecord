<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers PdoDbSession::isRetryableTransactionError() per driver: a real SQLITE_BUSY plus crafted
 * driver exceptions for the SQLite / MySQL / PostgreSQL branches. The MySQL and PostgreSQL cases
 * need a live server for driver detection and are grouped accordingly.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class RetryableClassificationTest extends TestCase
{
    public function testSqliteClassifiesBusyAndLockedButNotOtherErrors(): void
    {
        $session = new PdoDbSession(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));

        $locked = new \PDOException('database is locked');
        $this->assertTrue($session->isRetryableTransactionError($locked));

        $busy = new \PDOException('busy');
        $busy->errorInfo = ['HY000', 5, 'database is busy'];
        $this->assertTrue($session->isRetryableTransactionError($busy));

        $this->assertFalse($session->isRetryableTransactionError(new \PDOException('no such table: t')));
        $this->assertFalse($session->isRetryableTransactionError(new \RuntimeException('not even a PDOException')));
    }

    public function testSqliteRealBusyIsClassifiedRetryable(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'attr_sqlite_busy_');
        try {
            $dsn = 'sqlite:'.$file;
            $writer = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $blocked = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $blocked->exec('PRAGMA busy_timeout=0'); // fail immediately instead of waiting

            $writer->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT)');
            $writer->beginTransaction();
            $writer->exec("INSERT INTO t (n) VALUES ('x')"); // hold the write lock

            $session = new PdoDbSession($blocked);
            try {
                $blocked->exec("INSERT INTO t (n) VALUES ('y')");
                $this->fail('expected a SQLITE_BUSY / database-locked error');
            } catch (\PDOException $e) {
                $this->assertTrue($session->isRetryableTransactionError($e));
            }

            $writer->rollBack();
        } finally {
            @unlink($file);
        }
    }

    /** @group mysql */
    public function testMysqlClassifiesDeadlockAndLockWaitTimeout(): void
    {
        $session = new PdoDbSession(self::pdo('mysql:host=127.0.0.1;port=3306', 'root', 'root'));

        $this->assertTrue($session->isRetryableTransactionError(self::pdoWithErrorInfo(1213, 'Deadlock found')));
        $this->assertTrue($session->isRetryableTransactionError(self::pdoWithErrorInfo(1205, 'Lock wait timeout exceeded')));
        $this->assertFalse($session->isRetryableTransactionError(self::pdoWithErrorInfo(1064, 'SQL syntax error')));
    }

    /** @group pgsql */
    public function testPgsqlClassifiesSerializationAndDeadlock(): void
    {
        $session = new PdoDbSession(self::pdo('pgsql:host=127.0.0.1;port=5432;dbname=attrecord_test', 'postgres', 'postgres'));

        $this->assertTrue($session->isRetryableTransactionError(self::pdoWithSqlState('40001')));  // serialization_failure
        $this->assertTrue($session->isRetryableTransactionError(self::pdoWithSqlState('40P01')));  // deadlock_detected
        $this->assertFalse($session->isRetryableTransactionError(self::pdoWithSqlState('42601'))); // syntax_error
    }

    private static function pdo(string $dsn, string $user, string $pass): \PDO
    {
        try {
            return new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            self::markTestSkipped("Database not available ({$e->getMessage()}).");
        }
    }

    /** A PDOException carrying a driver error code in errorInfo[1] (MySQL/SQLite style). */
    private static function pdoWithErrorInfo(int $driverCode, string $message): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = ['HY000', $driverCode, $message];

        return $e;
    }

    /** A PDOException whose getCode() is a five-char SQLSTATE (PostgreSQL style). */
    private static function pdoWithSqlState(string $sqlState): \PDOException
    {
        $e = new \PDOException('crafted');
        $property = new \ReflectionProperty(\Exception::class, 'code');
        $property->setValue($e, $sqlState);

        return $e;
    }
}
