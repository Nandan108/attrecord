<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Tests\Fixtures\BinaryRefRecord;
use Nandan108\Attrecord\WhereClause;

/**
 * Binary values carried by a **predicate** rather than by a key, against a real engine.
 *
 * The coverage this fills is the reason the bug survived: every other binary case addresses rows by
 * primary key, and a key has always bound through `Record::pkParams()` and so has always been
 * marked. A binary value in a `WhereClause` took a different path and was not — so the same bytes
 * were correct as a key and wrong as a condition, on the same table, in the same request.
 *
 * **These cases fail on PostgreSQL without the fix**, which is what makes them worth having: bound
 * as a text parameter, raw bytes draw `Character not in repertoire` from PDO_pgsql. They are not a
 * SQLite-translator accommodation; PostgreSQL had this bug the whole time and nothing here asked it
 * the question.
 *
 * Every case is written so that binding the wrong thing cannot look like success. Two owners hold
 * rows throughout, so a predicate that matched nothing and one that matched everything are both
 * visible as a wrong count rather than as an empty result that reads like "no such rows".
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait BinaryPredicateCases
{
    /**
     * Two 16-byte owner ids differing only in their LAST byte, so a predicate that compared a
     * prefix — or bound the wrong thing and matched broadly — cannot pass by luck.
     *
     * Methods rather than constants: a trait cannot declare a constant before PHP 8.2, and this
     * package supports 8.1.
     */
    private static function ownerA(): string
    {
        return "\x01\x92\xf8\xa4\xb3\xc2\x7d\x4e\x8f\x1a\x2b\x3c\x4d\x5e\x6f\x70";
    }

    private static function ownerB(): string
    {
        return "\x01\x92\xf8\xa4\xb3\xc2\x7d\x4e\x8f\x1a\x2b\x3c\x4d\x5e\x6f\x71";
    }

    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        return [BinaryRefRecord::class];
    }

    /** Two rows for owner A, one for owner B — so "all" and "none" are both wrong answers. */
    private function seedRefs(): void
    {
        foreach ([[self::ownerA(), 'a1', 1], [self::ownerA(), 'a2', 2], [self::ownerB(), 'b1', 3]] as [$owner, $label, $qty]) {
            BinaryRefRecord::newWith(['owner_id' => $owner, 'label' => $label, 'qty' => $qty])->save();
        }
    }

    public function testFindByABinaryPredicateReturnsTheRightRows(): void
    {
        $this->seedRefs();

        $rows = BinaryRefRecord::find(WhereClause::where('owner_id', self::ownerA()));
        $labels = array_map(static fn (BinaryRefRecord $r): string => $r->label, iterator_to_array($rows));
        sort($labels);

        $this->assertSame(['a1', 'a2'], $labels, 'owner A only — B differs by its last byte');
    }

    /** The bytes survive the round trip, so the value read back is the value asked for. */
    public function testTheBinaryValueReadsBackIdentically(): void
    {
        $this->seedRefs();

        $rows = iterator_to_array(BinaryRefRecord::find(WhereClause::where('owner_id', self::ownerB())));

        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertInstanceOf(BinaryRefRecord::class, $row);
        $this->assertSame(self::ownerB(), $row->owner_id);
    }

    /** countWhere is one of the seven WhereClause entry points; it binds through the same path. */
    public function testCountWhereBindsTheBinaryValue(): void
    {
        $this->seedRefs();

        $this->assertSame(2, BinaryRefRecord::countWhere(WhereClause::where('owner_id', self::ownerA())));
        $this->assertSame(1, BinaryRefRecord::countWhere(WhereClause::where('owner_id', self::ownerB())));
    }

    public function testAnInListOfBinaryValuesMatchesEachOfThem(): void
    {
        $this->seedRefs();

        $this->assertSame(
            3,
            BinaryRefRecord::countWhere(WhereClause::whereIn('owner_id', [self::ownerA(), self::ownerB()])),
            'both owners, so every row',
        );
        $this->assertSame(
            2,
            BinaryRefRecord::countWhere(WhereClause::whereIn('owner_id', [self::ownerA()])),
            'a one-element IN still discriminates',
        );
    }

    /** A write path, not just a read: updateWhere binds its predicate the same way. */
    public function testUpdateWhereTouchesOnlyTheMatchingOwner(): void
    {
        $this->seedRefs();

        BinaryRefRecord::updateWhere(['qty' => 99], WhereClause::where('owner_id', self::ownerB()));

        $this->assertSame(1, BinaryRefRecord::countWhere(WhereClause::where('qty', 99)));
        $this->assertSame(
            0,
            BinaryRefRecord::countWhere(
                WhereClause::where('owner_id', self::ownerA())->andWhere(WhereClause::where('qty', 99)),
            ),
            "owner A's rows are untouched",
        );
    }

    /** And a delete, which is the case where binding nothing would look like a clean no-op. */
    public function testDeleteWhereRemovesOnlyTheMatchingOwner(): void
    {
        $this->seedRefs();

        $deleted = BinaryRefRecord::deleteWhere(WhereClause::where('owner_id', self::ownerA()));

        $this->assertSame(2, $deleted);
        $this->assertSame(1, BinaryRefRecord::countWhere(WhereClause::where('owner_id', self::ownerB())));
    }
}
