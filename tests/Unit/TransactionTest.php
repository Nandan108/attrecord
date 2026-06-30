<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Exception\LockAssertionException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use Nandan108\Attrecord\Transaction;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@see Transaction} lock-tracking stack and the assertLocked() development guard.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class TransactionTest extends TestCase
{
    protected function setUp(): void
    {
        Record::setConnection(new Connection(new CapturingDbSession(), new MysqlDialect()));
        TableSchema::clearCache();
    }

    protected function tearDown(): void
    {
        // Drain any frames a test left on the static stack.
        while (null !== Transaction::current()) {
            Transaction::pop();
        }
    }

    public function testCurrentIsNullOutsideTransaction(): void
    {
        $this->assertNull(Transaction::current());
    }

    public function testPushPopMaintainsAStack(): void
    {
        $outer = Transaction::push();
        $this->assertSame($outer, Transaction::current());

        $inner = Transaction::push();
        $this->assertSame($inner, Transaction::current());
        $this->assertNotSame($outer, $inner);

        Transaction::pop();
        $this->assertSame($outer, Transaction::current());

        Transaction::pop();
        $this->assertNull(Transaction::current());
    }

    public function testRegisterLockAndAssertLockedNoopWhenDisabled(): void
    {
        $tx = Transaction::push();

        $user = new UserRecord();
        $user->id = 1;
        $tx->registerLock($user);

        // With ATTRECORD_LOCK_ASSERTIONS undefined (the production default), assertLocked is a
        // no-op even for a record that was never registered.
        $unlocked = new UserRecord();
        $unlocked->id = 99;
        $tx->assertLocked($unlocked);
        $tx->assertLocked($user);

        $this->expectNotToPerformAssertions();
    }

    #[RunInSeparateProcess]
    public function testAssertLockedEnforcedWhenEnabled(): void
    {
        define('ATTRECORD_LOCK_ASSERTIONS', true);

        Record::setConnection(new Connection(new CapturingDbSession(), new MysqlDialect()));
        TableSchema::clearCache();

        $tx = Transaction::push();

        $locked = new UserRecord();
        $locked->id = 1;
        $tx->registerLock($locked);

        // A registered record passes.
        $tx->assertLocked($locked);

        // An unregistered record throws.
        $unlocked = new UserRecord();
        $unlocked->id = 2;

        $this->expectException(LockAssertionException::class);
        $tx->assertLocked($unlocked);
    }
}
