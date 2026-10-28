<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Enum\UpsertStrategy;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\CompositePkTimestampRecord;
use Nandan108\Attrecord\Tests\Fixtures\MintedPkUpsertTimestampRecord;

/**
 * `#[CreatedAt]` / `#[UpdatedAt]` through {@see RecordSet::upsertAll()} when the **database** decides
 * the branch — a freshly built record whose key the caller minted, so its presence proves neither
 * that the row exists nor that it does not.
 *
 * Two keys of that kind, both run under both strategies:
 *  - a non-autoincrement single-column PK (an application-generated id);
 *  - a composite PK.
 *
 * Each re-touch builds a **new** object for a key already stored, which is how a caller that
 * re-asserts a row on every request behaves. Created_at must be written on the INSERT branch and
 * left alone on the conflict branch; updated_at moves on both.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 *
 * @phpstan-require-implements \Nandan108\Attrecord\Tests\Support\BackendIntegrationTest
 */
trait UpsertAllTimestampCases
{
    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        return [MintedPkUpsertTimestampRecord::class, CompositePkTimestampRecord::class];
    }

    // -----------------------------------------------------------------
    // Caller-minted single-column PK
    // -----------------------------------------------------------------

    public function testLockedUpsertStampsCreatedAtOnMintedPk(): void
    {
        $this->assertMintedPkStamped(UpsertStrategy::Locked);
    }

    public function testLocklessUpsertStampsCreatedAtOnMintedPk(): void
    {
        $this->assertMintedPkStamped(UpsertStrategy::Lockless);
    }

    public function testLockedRetouchKeepsCreatedAtOnMintedPk(): void
    {
        $this->assertMintedPkRetouchKeepsCreatedAt(UpsertStrategy::Locked);
    }

    public function testLocklessRetouchKeepsCreatedAtOnMintedPk(): void
    {
        $this->assertMintedPkRetouchKeepsCreatedAt(UpsertStrategy::Lockless);
    }

    public function testExplicitCreatedAtSurvivesOnMintedPk(): void
    {
        // Importing historical rows: a created_at the caller supplied is never overwritten by the
        // stamp, on either strategy.
        $historic = new \DateTimeImmutable('2001-02-03 04:05:06.000000');
        foreach ([UpsertStrategy::Locked, UpsertStrategy::Lockless] as $i => $strategy) {
            $r = self::minted(700 + $i, 'historic');
            $r->created_at = $historic;
            (new RecordSet([$r]))->upsertAll(strategy: $strategy);

            [$created] = self::mintedStamps(700 + $i);
            $this->assertSame($historic->format('Y-m-d H:i:s.u'), $created, $strategy->name);
        }
    }

    public function testLoadedRecordUpsertLeavesCreatedAtAndBumpsUpdatedAt(): void
    {
        // The known-branch path: a record read back from the database is an UPDATE, and keeps
        // stamping the way it always has — updated_at only, and only on a real change.
        (new RecordSet([self::minted(800, 'first')]))->upsertAll();
        [$created, $updated] = self::mintedStamps(800);

        usleep(2000);
        $loaded = MintedPkUpsertTimestampRecord::getOne(800) ?? throw new \RuntimeException('row 800 missing');
        $loaded->name = 'second';
        (new RecordSet([$loaded]))->upsertAll();

        [$created2, $updated2] = self::mintedStamps(800);
        $this->assertSame($created, $created2);
        $this->assertGreaterThan($updated, $updated2);
    }

    // -----------------------------------------------------------------
    // Composite PK
    // -----------------------------------------------------------------

    public function testLockedRetouchKeepsCreatedAtOnCompositePk(): void
    {
        $this->assertCompositeRetouchKeepsCreatedAt(UpsertStrategy::Locked);
    }

    public function testLocklessRetouchKeepsCreatedAtOnCompositePk(): void
    {
        $this->assertCompositeRetouchKeepsCreatedAt(UpsertStrategy::Lockless);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function assertMintedPkStamped(UpsertStrategy $strategy): void
    {
        // NOT NULL with no default: an unstamped created_at fails the INSERT outright, so the write
        // landing at all is half the assertion.
        $r = self::minted(501, 'fresh');
        (new RecordSet([$r]))->upsertAll(strategy: $strategy);

        $this->assertNotNull($r->created_at, 'stamped on the object');
        [$created, $updated] = self::mintedStamps(501);
        $this->assertSame($created, $updated, 'both stamps come from the one INSERT');
    }

    private function assertMintedPkRetouchKeepsCreatedAt(UpsertStrategy $strategy): void
    {
        (new RecordSet([self::minted(601, 'first')]))->upsertAll(strategy: $strategy);
        [$created, $updated] = self::mintedStamps(601);
        // Without this, an unstamped created_at stored as a zero date (MySQL's INSERT IGNORE demotes
        // the NOT NULL error to a warning) equals itself across both writes and passes the check below.
        $this->assertSame($created, $updated, 'both stamps come from the one INSERT');

        usleep(2000);
        (new RecordSet([self::minted(601, 'second')]))->upsertAll(strategy: $strategy);

        [$created2, $updated2] = self::mintedStamps(601);
        $this->assertSame('second', MintedPkUpsertTimestampRecord::getOne(601)?->name);
        $this->assertSame($created, $created2, 'the conflict branch must not restamp created_at');
        $this->assertGreaterThan($updated, $updated2);
    }

    private function assertCompositeRetouchKeepsCreatedAt(UpsertStrategy $strategy): void
    {
        (new RecordSet([self::composite(1, 2, 'first')]))->upsertAll(strategy: $strategy);
        [$created, $updated] = self::compositeStamps(1, 2);
        $this->assertSame($created, $updated, 'both stamps come from the one INSERT');

        usleep(2000);
        (new RecordSet([self::composite(1, 2, 'second')]))->upsertAll(strategy: $strategy);

        [$created2, $updated2] = self::compositeStamps(1, 2);
        $this->assertSame($created, $created2, 'the conflict branch must not restamp created_at');
        $this->assertGreaterThan($updated, $updated2);
    }

    private static function minted(int $id, string $name): MintedPkUpsertTimestampRecord
    {
        $r = new MintedPkUpsertTimestampRecord();
        $r->id = $id;
        $r->name = $name;

        return $r;
    }

    private static function composite(int $owner, int $slot, string $name): CompositePkTimestampRecord
    {
        $r = new CompositePkTimestampRecord();
        $r->owner_id = $owner;
        $r->slot_id = $slot;
        $r->name = $name;

        return $r;
    }

    /** @return array{0: string, 1: string} stored `[created_at, updated_at]`, to the microsecond */
    private static function mintedStamps(int $id): array
    {
        $row = MintedPkUpsertTimestampRecord::getOne($id)
            ?? throw new \RuntimeException("no attrecord_minted_ts_upsert row {$id}");

        return self::formatStamps($row->created_at, $row->updated_at);
    }

    /** @return array{0: string, 1: string} stored `[created_at, updated_at]`, to the microsecond */
    private static function compositeStamps(int $owner, int $slot): array
    {
        $row = CompositePkTimestampRecord::findOne('owner_id = ? AND slot_id = ?', [$owner, $slot])
            ?? throw new \RuntimeException("no attrecord_composite_ts row ({$owner}, {$slot})");

        return self::formatStamps($row->created_at, $row->updated_at);
    }

    /** @return array{0: string, 1: string} */
    private static function formatStamps(?\DateTimeImmutable $created, ?\DateTimeImmutable $updated): array
    {
        return [
            ($created ?? throw new \RuntimeException('created_at is null'))->format('Y-m-d H:i:s.u'),
            ($updated ?? throw new \RuntimeException('updated_at is null'))->format('Y-m-d H:i:s.u'),
        ];
    }
}
