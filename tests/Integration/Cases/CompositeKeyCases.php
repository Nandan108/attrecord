<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Tests\Fixtures\CompositeKeyCostRecord;

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
        return [CompositeKeyCostRecord::class];
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
