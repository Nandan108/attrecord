<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
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

    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        Record::setConnection(new Connection($this->session, new MysqlDialect()));
        TableSchema::clearCache();
    }

    public function testAcquiresInAscendingTierOrder(): void
    {
        // Pass higher tier first; LockSet must still query tier 1 (alpha) before tier 2 (beta).
        $result = LockSet::acquire($this->session, [
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
        $result = LockSet::acquire($this->session, [LockAlphaRecord::class => []]);

        $this->assertSame([], $this->session->allCalls());
        $this->assertInstanceOf(RecordSet::class, $result[LockAlphaRecord::class]);
        $this->assertCount(0, $result[LockAlphaRecord::class]);
    }

    public function testMissingLockTierThrows(): void
    {
        $this->expectException(MissingLockTierException::class);
        // UserRecord has no #[LockTier].
        LockSet::acquire($this->session, [UserRecord::class => [1]]);
    }

    public function testTierConflictThrows(): void
    {
        $this->expectException(LockTierConflictException::class);
        // LockAlphaRecord and LockAlphaDupRecord both declare tier 1.
        LockSet::acquire($this->session, [
            LockAlphaRecord::class    => [1],
            LockAlphaDupRecord::class => [2],
        ]);
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
