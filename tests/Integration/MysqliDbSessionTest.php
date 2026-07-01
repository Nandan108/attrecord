<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\MysqliDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the mysqli-backed DbSession against a live MySQL/MariaDB server, covering the
 * adapter's exec / fetch / transaction / advisory-lock / duplicate-key paths.
 *
 * @group mysql
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class MysqliDbSessionTest extends TestCase
{
    private static \mysqli $mysqli;

    public static function setUpBeforeClass(): void
    {
        $env = static fn (string $k, string $d): string => (($v = getenv($k)) !== false && '' !== $v) ? $v : $d;

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            self::$mysqli = new \mysqli(
                $env('DB_HOST', '127.0.0.1'),
                $env('DB_USER', 'root'),
                $env('DB_PASS', 'root'),
                $env('DB_NAME', 'attrecord_test'),
                (int) $env('DB_PORT', '3306'),
            );
        } catch (\Throwable $e) {
            self::markTestSkipped("MySQL not available for mysqli ({$e->getMessage()}).");
        }

        $dialect = new MysqlDialect();
        Record::setConnection(new Connection(new MysqliDbSession(self::$mysqli), $dialect));
        TableSchema::clearCache();
        self::$mysqli->query($dialect->buildCreateTable(TableSchema::fromClass(UserRecord::class), ifNotExists: true));
    }

    protected function setUp(): void
    {
        // attrecord_users may be referenced by FKs from other suites' tables in the shared DB.
        self::$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
        self::$mysqli->query('TRUNCATE TABLE `attrecord_users`');
        self::$mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testInsertGetUpdateDelete(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->save();
        $this->assertNotNull($user->id);          // lastInsertId()

        $found = UserRecord::getOne($user->id); // fetchOne()
        $this->assertNotNull($found);
        $this->assertSame('Alice', $found->name);

        $found->name = 'Alicia';
        $found->save();
        $this->assertSame('Alicia', UserRecord::getOne($user->id)?->name);

        $found->delete();
        $this->assertNull(UserRecord::getOne($user->id));
    }

    public function testFindAndCountWhere(): void
    {
        foreach (['Alice', 'Bob', 'Charlie'] as $name) {
            $u = new UserRecord();
            $u->name = $name;
            $u->save();
        }

        $this->assertCount(3, UserRecord::find());                  // fetchAll()
        $this->assertSame(3, UserRecord::countWhere('id > 0'));     // fetchScalar()
        $this->assertSame(1, UserRecord::countWhere('name = ?', ['Bob']));
    }

    public function testTransactionalCommitAndRollback(): void
    {
        UserRecord::transactional(function (): void {
            (new UserRecord())->set(['name' => 'Committed'])->save();
        });
        $this->assertSame(1, UserRecord::countWhere('id > 0'));

        try {
            UserRecord::transactional(function (): void {
                (new UserRecord())->set(['name' => 'RolledBack'])->save();
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, UserRecord::countWhere('id > 0'));
    }

    public function testWithAdvisoryLock(): void
    {
        $result = Record::connection()->session->withAdvisoryLock(
            'attrecord_mysqli_lock',
            5,
            static fn (): string => 'locked-section',
        );
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame('locked-section', $result);
    }

    public function testIsDuplicateKeyError(): void
    {
        $session = Record::connection()->session;
        $session->exec('INSERT INTO `attrecord_users` (`id`, `name`) VALUES (?, ?)', [1, 'first']);

        try {
            $session->exec('INSERT INTO `attrecord_users` (`id`, `name`) VALUES (?, ?)', [1, 'dup']);
            $this->fail('expected a duplicate-key violation');
        } catch (\Throwable $e) {
            $this->assertTrue($session->isDuplicateKeyError($e));
        }
    }

    public function testIsRetryableTransactionError(): void
    {
        $session = Record::connection()->session;
        $this->assertInstanceOf(\Nandan108\Attrecord\RetryableErrorClassifier::class, $session);

        // A mysqli_sql_exception carries the errno via getCode() — 1213/1205/1020 are retryable.
        $this->assertTrue($session->isRetryableTransactionError(new \mysqli_sql_exception('Deadlock found', 1213)));
        $this->assertTrue($session->isRetryableTransactionError(new \mysqli_sql_exception('Lock wait timeout exceeded', 1205)));
        $this->assertFalse($session->isRetryableTransactionError(new \mysqli_sql_exception('Duplicate entry', 1062)));
    }
}
