<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Enum\UpsertStrategy;
use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\CompositeKeyCostRecord;
use Nandan108\Attrecord\Tests\Fixtures\CompositeKeyCostRefRecord;

/**
 * Composite primary keys, against a real engine.
 *
 * The unit tests assert the SQL that gets built; these assert that three engines accept it and
 * that the rows it addresses are the intended ones. Both are needed and neither substitutes: a
 * predicate can be spelled correctly and still select the wrong rows, and a row-value constructor
 * is exactly the kind of syntax whose support varies by engine and version.
 *
 * **Every case is written so that matching on `subject_id` alone would fail it.** Subject 1 holds
 * two areas throughout, so any path that drops a key member reads, writes or locks a row it was
 * not asked for.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait CompositeKeyCases
{
    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        // Parent first: the child's FOREIGN KEY is inline in its CREATE TABLE.
        return [CompositeKeyCostRecord::class, CompositeKeyCostRefRecord::class];
    }

    /** Subject 1 in two areas, subject 2 sharing one of those areas. */
    private function seedCosts(): void
    {
        foreach ([[1, 10, '5.0000'], [1, 20, '7.5000'], [2, 10, '9.2500']] as [$subject, $area, $cost]) {
            CompositeKeyCostRecord::newWith([
                'subject_id' => $subject,
                'area_id'    => $area,
                'unit_cost'  => $cost,
            ])->save();
        }
    }

    public function testInsertAndReadBackByWholeKey(): void
    {
        $this->seedCosts();

        $row = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 20]);

        $this->assertNotNull($row);
        // Compared numerically: SQLite stores a DECIMAL unpadded ('7.5'), so an exact string
        // match would be asserting the engine's formatting rather than which row came back.
        $this->assertSame(7.5, (float) $row->unit_cost, 'the second area of subject 1, not the first');
        $this->assertSame(['subject_id' => 1, 'area_id' => 20], $row->pkValues());
    }

    /** The key members survive the round trip — no member is backfilled from a generated id. */
    public function testAnInsertedRowKeepsTheKeyItWasGiven(): void
    {
        $row = CompositeKeyCostRecord::newWith(['subject_id' => 77, 'area_id' => 88, 'unit_cost' => '1.0000']);
        $row->save();

        $this->assertSame(77, $row->subject_id);
        $this->assertSame(88, $row->area_id, 'the non-leading member is not clobbered');
        $this->assertFalse($row->isNew());
    }

    /**
     * The whole point of a multi-column foreign key, on a real engine: the DDL was accepted, and
     * the constraint it created is over **both** columns.
     *
     * A pair that exists is admitted; a pair whose first member exists and whose second does not is
     * rejected. That second insert is the discriminating one — under the single-column constraint
     * attrecord emitted before v0.23, `subject_id = 1` satisfies it and the row goes in.
     */
    public function testACompositeForeignKeyEnforcesThePairNotItsFirstMember(): void
    {
        $this->seedCosts();

        CompositeKeyCostRefRecord::newWith([
            'cost_subject_id' => 1,
            'cost_area_id'    => 20,
            'label'           => 'an existing pair',
        ])->save();

        $this->assertSame(1, $this->countRefsOfSubject(1));

        $rejected = false;
        try {
            CompositeKeyCostRefRecord::newWith([
                'cost_subject_id' => 1,    // exists
                'cost_area_id'    => 999,  // does not, and (1, 999) is not a row
                'label'           => 'half a key',
            ])->save();
        } catch (\Throwable) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'a pair that is not a row must be refused, though its first member is one');
        $this->assertSame(1, $this->countRefsOfSubject(1), 'and nothing was written');
    }

    private function countRefsOfSubject(int $subjectId): int
    {
        return \count(iterator_to_array(CompositeKeyCostRefRecord::where('cost_subject_id', $subjectId)));
    }

    /** The referential action applies to the pair too: deleting the parent row takes its children. */
    public function testCascadeFollowsTheWholeKey(): void
    {
        $this->seedCosts();

        foreach ([[1, 10], [1, 20]] as [$subject, $area]) {
            CompositeKeyCostRefRecord::newWith([
                'cost_subject_id' => $subject,
                'cost_area_id'    => $area,
            ])->save();
        }

        $parent = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]);
        $this->assertNotNull($parent);
        $parent->delete();

        $survivors = CompositeKeyCostRefRecord::where('cost_subject_id', 1);
        $remaining = array_map(
            static fn (CompositeKeyCostRefRecord $r): ?int => $r->cost_area_id,
            iterator_to_array($survivors),
        );

        // Only the child of (1, 10) goes. Cascading on subject_id alone would take both.
        $this->assertSame([20], array_values($remaining));
    }

    public function testUpdateTouchesOnlyTheKeyedRow(): void
    {
        $this->seedCosts();

        $row = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]);
        $this->assertNotNull($row);
        $row->unit_cost = '6.0000';
        $row->save();

        // The sibling sharing subject_id must be untouched. On `WHERE subject_id = ?` alone this
        // UPDATE would have rewritten it too, and nothing would have errored.
        $sibling = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 20]);
        $this->assertNotNull($sibling);
        $this->assertSame(7.5, (float) $sibling->unit_cost, 'the other area of the same subject is untouched');

        $reloaded = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]);
        $this->assertNotNull($reloaded);
        $this->assertSame(6.0, (float) $reloaded->unit_cost);
    }

    public function testDeleteRemovesOnlyTheKeyedRow(): void
    {
        $this->seedCosts();

        $row = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]);
        $this->assertNotNull($row);
        $row->delete();

        $this->assertNull(CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]));
        $this->assertNotNull(
            CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 20]),
            'the other area of the same subject survives',
        );
        $this->assertNotNull(CompositeKeyCostRecord::getOne(['subject_id' => 2, 'area_id' => 10]));
    }

    public function testReloadReadsBackOnTheWholeKey(): void
    {
        $this->seedCosts();

        $row = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 20]);
        $this->assertNotNull($row);
        $row->unit_cost = 'not saved';
        $row->reload();

        $this->assertSame(7.5, (float) $row->unit_cost);
    }

    public function testGetOneOrNewPrePopulatesEveryKeyMember(): void
    {
        $row = CompositeKeyCostRecord::getOneOrNew(['subject_id' => 41, 'area_id' => 42]);

        $this->assertTrue($row->isNew());
        $this->assertSame(41, $row->subject_id);
        $this->assertSame(42, $row->area_id);
    }

    public function testInsertAllAndDeleteAllWorkOnWholeKeys(): void
    {
        $rows = [];
        foreach ([[3, 30], [3, 31], [4, 30]] as [$subject, $area]) {
            $rows[] = CompositeKeyCostRecord::newWith([
                'subject_id' => $subject,
                'area_id'    => $area,
                'unit_cost'  => '2.0000',
            ]);
        }
        (new RecordSet($rows))->insertAll();

        $this->assertNotNull(CompositeKeyCostRecord::getOne(['subject_id' => 3, 'area_id' => 31]));

        // Delete two of the three, naming each by its whole key. (3, 30) and (4, 30) share an
        // area and (3, 30) shares a subject with (3, 31), so a partial-key IN would take more.
        $a = CompositeKeyCostRecord::getOne(['subject_id' => 3, 'area_id' => 30]);
        $b = CompositeKeyCostRecord::getOne(['subject_id' => 4, 'area_id' => 30]);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame(2, (new RecordSet([$a, $b]))->deleteAll());

        $this->assertNull(CompositeKeyCostRecord::getOne(['subject_id' => 3, 'area_id' => 30]));
        $this->assertNull(CompositeKeyCostRecord::getOne(['subject_id' => 4, 'area_id' => 30]));
        $this->assertNotNull(
            CompositeKeyCostRecord::getOne(['subject_id' => 3, 'area_id' => 31]),
            'the survivor shares a subject with one of the deleted rows',
        );
    }

    /**
     * The shape the feature exists for: a WAC recompute locks the rows it knows about, mutates
     * them, mints rows for subjects it has not costed before, and writes the lot in one
     * `upsertAll()`. Inserts and updates in a single batch, on a caller-minted key.
     *
     * This is also where the insert/update partition earns its keep. The locked rows are hydrated
     * and the minted ones are not, which is the *only* difference between them — both carry a
     * complete key. A partition keyed on "is the key present" would call every row an update and
     * never insert; one keyed on the record's own state gets both right.
     */
    public function testUpsertAllMixesMintedAndLockedRowsOnACallerMintedKey(): void
    {
        $this->seedCosts();

        $connection = Record::connection();
        $connection->session->transactional(function () use ($connection): void {
            $locked = LockSet::acquire($connection, [CompositeKeyCostRecord::class => [
                ['subject_id' => 1, 'area_id' => 10],
            ]]);

            $rows = [];
            foreach ($locked[CompositeKeyCostRecord::class] as $row) {
                /** @var CompositeKeyCostRecord $row */
                $row->unit_cost = '11.0000';   // an existing row, updated
                $rows[] = $row;
            }
            $rows[] = CompositeKeyCostRecord::newWith([   // a brand-new area for a known subject
                'subject_id' => 1,
                'area_id'    => 30,
                'unit_cost'  => '12.0000',
            ]);

            (new RecordSet($rows))->upsertAll();
        });

        $updated = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]);
        $inserted = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 30]);
        $untouched = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 20]);

        $this->assertNotNull($updated);
        $this->assertNotNull($inserted, 'the minted row was inserted, not swallowed as an update');
        $this->assertNotNull($untouched);
        $this->assertSame(11.0, (float) $updated->unit_cost);
        $this->assertSame(12.0, (float) $inserted->unit_cost);
        // Shares a subject with both of the above and was in neither batch entry.
        $this->assertSame(7.5, (float) $untouched->unit_cost, 'the sibling area is untouched');
    }

    /**
     * **A complete composite key never proves the row is absent.** The caller minted every member,
     * and minting them says nothing about what is stored — so a minted row must be written through
     * the upsert, not as a plain INSERT.
     *
     * This is the membership-table pattern: a workbench subscription re-sends the subjects it
     * watches on every page load, most of which are already rows. Treating "never hydrated" as
     * "known to be new" turns that into a duplicate-key error on the second load.
     */
    public function testUpsertAllAcceptsAMintedRowThatAlreadyExists(): void
    {
        $this->seedCosts();

        // Same key as a seeded row, built fresh — the caller does not know whether it exists.
        $minted = CompositeKeyCostRecord::newWith([
            'subject_id' => 1,
            'area_id'    => 10,
            'unit_cost'  => '99.0000',
        ]);
        (new RecordSet([$minted]))->upsertAll();

        $row = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]);
        $this->assertNotNull($row);
        $this->assertSame(99.0, (float) $row->unit_cost, 'the existing row was updated, not duplicated');

        $siblings = CompositeKeyCostRecord::where('subject_id', 1);
        $this->assertCount(2, [...$siblings], 'still two areas for subject 1 — nothing was inserted');
    }

    /**
     * The lockless single-statement strategy coalesces on the **whole** key.
     *
     * PostgreSQL names the conflict target explicitly and rejects a partial one — `ON CONFLICT (a)`
     * on a table keyed `(a, b)` matches no constraint. MySQL infers it from `ON DUPLICATE KEY` and
     * would coalesce correctly by luck, so this only fails on one of the two engines, which is the
     * reason it is asserted here rather than trusted.
     *
     * Both record shapes are exercised: a hydrated row, and a minted one carrying the whole key.
     * The second is not a null-PK record — the guard that rejects those is about a surrogate key
     * with no value yet, and every member of a composite key comes from the caller.
     */
    public function testLocklessUpsertCoalescesOnTheWholeKey(): void
    {
        $this->seedCosts();

        $hydrated = CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10]);
        $this->assertNotNull($hydrated);
        $hydrated->unit_cost = '8.0000';
        (new RecordSet([$hydrated]))->upsertAll(strategy: UpsertStrategy::Lockless);

        $minted = CompositeKeyCostRecord::newWith([
            'subject_id' => 1,
            'area_id'    => 20,
            'unit_cost'  => '9.0000',
        ]);
        (new RecordSet([$minted]))->upsertAll(strategy: UpsertStrategy::Lockless);

        $this->assertSame(8.0, (float) CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10])?->unit_cost);
        $this->assertSame(9.0, (float) CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 20])?->unit_cost);
        // seedCosts() gives subject 1 two areas; both were coalesced onto, neither duplicated.
        $this->assertCount(2, [...CompositeKeyCostRecord::where('subject_id', 1)], 'coalesced, not duplicated');
    }

    /** The chunked path sorts and locks by the whole key too, across a mixed batch. */
    public function testChunkedUpsertHandlesAMixedBatch(): void
    {
        $this->seedCosts();

        $rows = [CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10])];
        $this->assertNotNull($rows[0]);
        $rows[0]->unit_cost = '6.0000';
        foreach ([[5, 50], [6, 60], [7, 70]] as [$subject, $area]) {
            $rows[] = CompositeKeyCostRecord::newWith([
                'subject_id' => $subject,
                'area_id'    => $area,
                'unit_cost'  => '1.0000',
            ]);
        }
        (new RecordSet($rows))->upsertAll(chunkSize: 2);

        $this->assertSame(6.0, (float) CompositeKeyCostRecord::getOne(['subject_id' => 1, 'area_id' => 10])?->unit_cost);
        $this->assertNotNull(CompositeKeyCostRecord::getOne(['subject_id' => 7, 'area_id' => 70]));
    }

    /**
     * The row-value `IN` plus tuple ordering, executed rather than merely built. This is the case
     * whose syntax support genuinely varies — and SQLite's own docs carried a "not supported" note
     * that has been stale since 3.15, which is reason to run it rather than read about it.
     */
    public function testLockSetTakesExactlyTheNamedRows(): void
    {
        $this->seedCosts();

        $connection = Record::connection();
        $locked = $connection->session->transactional(static fn (): array => LockSet::acquire(
            $connection,
            [CompositeKeyCostRecord::class => [
                ['subject_id' => 1, 'area_id' => 20],
                ['subject_id' => 2, 'area_id' => 10],
            ]],
        ));

        $keys = array_map(
            static fn (Record $r): array => $r->pkValues(),
            iterator_to_array($locked[CompositeKeyCostRecord::class]),
        );

        // Ascending lexicographic order over the whole key, which is the deadlock guarantee.
        $this->assertSame(
            [['subject_id' => 1, 'area_id' => 20], ['subject_id' => 2, 'area_id' => 10]],
            array_values($keys),
        );
        // (1, 10) shares a subject with the first and an area with the second, and was named by
        // neither — so a partial-key match would have locked it.
        $this->assertCount(2, $keys);
    }
}
