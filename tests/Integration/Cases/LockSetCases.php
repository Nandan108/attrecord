<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\LockAlphaRecord;
use Nandan108\Attrecord\Tests\Fixtures\LockBetaRecord;
use Nandan108\Attrecord\Transaction;

/**
 * Real `SELECT … FOR UPDATE` locking via LockSet inside a transaction, run against both MySQL
 * and PostgreSQL (LockSet quotes identifiers through each target class's dialect).
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait LockSetCases
{
    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        return [LockAlphaRecord::class, LockBetaRecord::class];
    }

    public function testAcquireLocksAndHydratesRowsInTierOrder(): void
    {
        $a1 = $this->makeAlpha('a-one');
        $a2 = $this->makeAlpha('a-two');
        $b1 = $this->makeBeta('b-one');

        LockAlphaRecord::transactional(function (Transaction $tx) use ($a1, $a2, $b1): void {
            $session = Record::connection()->session;

            $locks = LockSet::acquire($session, [
                // Pass beta (tier 2) first; LockSet must lock alpha (tier 1) first regardless.
                LockBetaRecord::class  => [(int) $b1->id],
                LockAlphaRecord::class => [(int) $a1->id, (int) $a2->id],
            ], $tx);

            $alpha = $locks[LockAlphaRecord::class];
            $beta = $locks[LockBetaRecord::class];
            $this->assertInstanceOf(RecordSet::class, $alpha);
            $this->assertInstanceOf(RecordSet::class, $beta);
            $this->assertCount(2, $alpha);
            $this->assertCount(1, $beta);

            // Rows are hydrated into the right Record subclass with their data.
            $this->assertInstanceOf(LockAlphaRecord::class, $alpha->first());
            $names = $alpha->pluck('name');
            sort($names);
            $this->assertSame(['a-one', 'a-two'], $names);
            $this->assertSame(['b-one'], array_values($beta->pluck('name')));
        });
    }

    public function testEmptyTargetsYieldEmptyRecordSets(): void
    {
        LockAlphaRecord::transactional(function (Transaction $tx): void {
            $session = Record::connection()->session;
            $locks = LockSet::acquire($session, [LockAlphaRecord::class => []], $tx);
            $this->assertCount(0, $locks[LockAlphaRecord::class]);
        });
    }

    private function makeAlpha(string $name): LockAlphaRecord
    {
        $r = new LockAlphaRecord();
        $r->name = $name;
        $r->save();

        return $r;
    }

    private function makeBeta(string $name): LockBetaRecord
    {
        $r = new LockBetaRecord();
        $r->name = $name;
        $r->save();

        return $r;
    }
}
