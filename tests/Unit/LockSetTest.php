<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\LockTierConflictException;
use Nandan108\Attrecord\Exception\MissingLockTierException;
use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\LockAlphaRecord;
use Nandan108\Attrecord\Tests\Fixtures\LockBetaRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * Covers LockSet tier validation, ordering, and SQL shape using a CapturingDbSession (the
 * actual row locking is covered by the MySQL integration test).
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class LockSetTest extends TestCase
{
    private CapturingDbSession $session;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        $this->connection = new Connection($this->session, new MysqlDialect());
        Record::setConnection($this->connection);
        TableSchema::clearCache();
    }

    /** One test clears the global connection; leave the next class a clean slate either way. */
    protected function tearDown(): void
    {
        Record::clearConnections();
    }

    public function testAcquiresInAscendingTierOrder(): void
    {
        // Pass higher tier first; LockSet must still query tier 1 (alpha) before tier 2 (beta).
        $result = LockSet::acquire($this->connection, [
            LockBetaRecord::class  => [2, 1],
            LockAlphaRecord::class => [10],
        ]);

        $calls = $this->session->allCalls();
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('attrecord_lock_alpha', $calls[0]['sql']);
        $this->assertStringContainsString('attrecord_lock_beta', $calls[1]['sql']);

        // SQL shape: IN-list + deterministic ascending-PK order + FOR UPDATE.
        $this->assertStringContainsString('FOR UPDATE', $calls[0]['sql']);
        $this->assertStringContainsString('ORDER BY `id` ASC', $calls[0]['sql']);
        $this->assertSame([10], $calls[0]['params']);
        $this->assertSame([2, 1], $calls[1]['params']);

        $this->assertArrayHasKey(LockAlphaRecord::class, $result);
        $this->assertArrayHasKey(LockBetaRecord::class, $result);
        $this->assertInstanceOf(RecordSet::class, $result[LockAlphaRecord::class]);
    }

    public function testEmptyIdListSkipsTheQuery(): void
    {
        $result = LockSet::acquire($this->connection, [LockAlphaRecord::class => []]);

        $this->assertSame([], $this->session->allCalls());
        $this->assertInstanceOf(RecordSet::class, $result[LockAlphaRecord::class]);
        $this->assertCount(0, $result[LockAlphaRecord::class]);
    }

    public function testMissingLockTierThrows(): void
    {
        $this->expectException(MissingLockTierException::class);
        // UserRecord has no #[LockTier].
        LockSet::acquire($this->connection, [UserRecord::class => [1]]);
    }

    public function testTierConflictThrows(): void
    {
        $this->expectException(LockTierConflictException::class);
        // LockAlphaRecord and LockAlphaDupRecord both declare tier 1.
        LockSet::acquire($this->connection, [
            LockAlphaRecord::class    => [1],
            LockAlphaDupRecord::class => [2],
        ]);
    }

    /**
     * The reason acquire() takes a Connection at all: a component handed one must be able to lock
     * on it without any global connection existing. Before 0.13 the dialect came from
     * `$class::connection()`, so this threw "No Connection configured" — and the failure only
     * appeared once FOR UPDATE was gated behind the dialect, since the SQL had been hard-coded
     * MySQL until then.
     */
    public function testLocksOnTheGivenConnectionWithNoGlobalConnectionConfigured(): void
    {
        Record::clearConnections();

        $session = new CapturingDbSession();
        $result = LockSet::acquire(new Connection($session, new MysqlDialect()), [
            LockAlphaRecord::class => [1],
        ]);

        self::assertArrayHasKey(LockAlphaRecord::class, $result);
        self::assertStringContainsString('FOR UPDATE', $session->allCalls()[0]['sql']);
    }

    /** The dialect is the given one, not whatever the global happens to be. */
    public function testTheGivenConnectionsDialectQuotesTheSql(): void
    {
        $session = new CapturingDbSession();
        LockSet::acquire(new Connection($session, new PgsqlDialect()), [
            LockAlphaRecord::class => [1],
        ]);

        // Postgres quoting, even though the global connection set up in setUp() is MySQL.
        self::assertStringContainsString('"attrecord_lock_alpha"', $session->allCalls()[0]['sql']);
    }
}

/** @internal Second tier-1 entity, to exercise the tier-conflict guard. */
#[Table(name: 'attrecord_lock_alpha_dup')]
#[LockTier(1)]
final class LockAlphaDupRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}
