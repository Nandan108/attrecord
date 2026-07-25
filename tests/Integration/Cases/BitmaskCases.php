<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Tests\Fixtures\BitmaskRecord;
use Nandan108\Attrecord\Tests\Fixtures\StockConcern;

/**
 * Shared, backend-agnostic cases for {@see \Nandan108\Attrecord\Caster\BitmaskCaster}: a set of enum
 * members persisted as an integer bitmask. Runs identically on MySQL/MariaDB, PostgreSQL and SQLite —
 * the storage is a plain integer, so the flag-set is portable.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase|\Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase
 */
trait BitmaskCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [BitmaskRecord::class];
    }

    /**
     * @param array<\BackedEnum> $concerns
     *
     * @return list<string>
     */
    private static function names(array $concerns): array
    {
        return array_values(array_map(static fn (\BackedEnum $c): string => $c->name, $concerns));
    }

    public function testRoundTripsAFlagSet(): void
    {
        $rec = new BitmaskRecord();
        $rec->concerns = [StockConcern::NoCost, StockConcern::Deficit];
        $rec->save();

        $reloaded = BitmaskRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        // Decomposed in the enum's declaration order regardless of insertion order.
        $this->assertSame(['Deficit', 'NoCost'], self::names($reloaded->concerns));
    }

    public function testEmptySetRoundTripsAsZero(): void
    {
        $rec = new BitmaskRecord();
        $rec->concerns = [];
        $rec->save();

        $reloaded = BitmaskRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame([], $reloaded->concerns);
    }

    public function testNullableFlagSetDistinguishesNullFromEmpty(): void
    {
        $rec = new BitmaskRecord();
        $rec->concerns = [StockConcern::Stale];
        $rec->optional_concerns = null;
        $rec->save();

        $reloaded = BitmaskRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->optional_concerns, 'NULL column hydrates to null, not []');

        $reloaded->optional_concerns = [];
        $reloaded->save();
        $again = BitmaskRecord::getOne((int) $rec->id);
        $this->assertNotNull($again);
        $this->assertSame([], $again->optional_concerns, 'empty set hydrates to [], not null');
    }

    public function testReorderingMembersIsNotDirtyButChangingMembershipIs(): void
    {
        $rec = new BitmaskRecord();
        $rec->concerns = [StockConcern::Deficit, StockConcern::Stale];
        $rec->save();

        $reloaded = BitmaskRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);

        // Same members, different order → same mask → not dirty.
        $reloaded->concerns = array_reverse($reloaded->concerns);
        $this->assertFalse($reloaded->isDirty('concerns'), 'reordering a flag-set must not dirty it');

        // Different membership → dirty.
        $reloaded->concerns = [StockConcern::Deficit];
        $this->assertTrue($reloaded->isDirty('concerns'));
    }

    public function testUpdatePersistsNewMembership(): void
    {
        $rec = new BitmaskRecord();
        $rec->concerns = [StockConcern::Deficit];
        $rec->save();

        $rec->concerns = [StockConcern::Overstock, StockConcern::NoCost];
        $rec->save();

        $reloaded = BitmaskRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(['Overstock', 'NoCost'], self::names($reloaded->concerns));
    }
}
