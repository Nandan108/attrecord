<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Exception\AppendOnlyViolationException;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\ImmutableDocRecord;
use Nandan108\Attrecord\Tests\Fixtures\ImmutableDocRefRecord;
use Nandan108\Attrecord\Tests\Fixtures\ImmutableTreeRecord;
use Nandan108\Attrecord\WhereClause;

/**
 * Shared cases for the {@see Immutable} contract: content that never changes, existence that may.
 *
 * The load-bearing half is the **asymmetry** — every update path throws, and every delete path
 * works. {@see AppendOnlyCases} proves the stricter promise on the same guards, so between them the
 * two markers are pinned apart; a regression that collapsed one into the other would fail here.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase|\Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase
 */
trait ImmutableCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [ImmutableDocRecord::class, ImmutableDocRefRecord::class, ImmutableTreeRecord::class];
    }

    private function intern(int $id, string $name): ImmutableDocRecord
    {
        $r = new ImmutableDocRecord();
        $r->id = $id;
        $r->name = $name;
        (new RecordSet([$r]))->insertAll();

        return $r;
    }

    /** Make a doc row referenced, so it is no longer reapable. */
    private function pointAt(int $docId): void
    {
        $ref = new ImmutableDocRefRecord();
        $ref->doc_id = $docId;
        $ref->save();
    }

    // --- the marker itself ---------------------------------------------

    public function testAppendOnlyIsTheStricterKindOfImmutable(): void
    {
        // The hierarchy is what keeps the update guards honest: they test Immutable alone, so an
        // append-only Record has to arrive there without restating anything.
        self::assertTrue(is_a(AppendOnly::class, Immutable::class, true));
        self::assertFalse(is_a(ImmutableDocRecord::class, AppendOnly::class, true));
        self::assertTrue(is_a(ImmutableDocRecord::class, Immutable::class, true));
    }

    // --- allowed paths --------------------------------------------------

    public function testInsertAndReadWork(): void
    {
        $r = $this->intern(1, 'interned');

        self::assertNotNull($r->created_at, 'insertAll stamps #[CreatedAt]');
        self::assertNotNull(ImmutableDocRecord::getOne(1));
        self::assertCount(1, ImmutableDocRecord::find(WhereClause::match(['name' => 'interned'])));
    }

    public function testNewRecordSaveIsAllowed(): void
    {
        $r = new ImmutableDocRecord();
        $r->id = 10;
        $r->name = 'single';
        $r->save(); // new record -> INSERT

        self::assertFalse($r->isNew());
        self::assertNotNull(ImmutableDocRecord::getOne(10));
    }

    // --- the asymmetry: deletes are permitted ---------------------------

    public function testDeleteIsAllowed(): void
    {
        // The whole reason this marker exists. An orphaned content-addressed row can be reaped,
        // because re-interning the same facts recomputes the same key.
        $r = $this->intern(20, 'orphan');

        $r->delete();

        self::assertNull(ImmutableDocRecord::getOne(20), 'the row is gone');
    }

    public function testDeleteWhereIsAllowed(): void
    {
        $this->intern(21, 'reapable');

        $affected = ImmutableDocRecord::deleteWhere(WhereClause::match(['id' => 21]));

        self::assertSame(1, $affected);
        self::assertNull(ImmutableDocRecord::getOne(21));
    }

    public function testDeleteAllIsAllowed(): void
    {
        // The bulk path a reaper actually uses.
        $a = $this->intern(22, 'reap-a');
        $b = $this->intern(23, 'reap-b');

        (new RecordSet([$a, $b]))->deleteAll();

        self::assertNull(ImmutableDocRecord::getOne(22));
        self::assertNull(ImmutableDocRecord::getOne(23));
    }

    // --- forbidden: every update path -----------------------------------

    public function testSaveOnExistingRowThrowsAndLeavesRowUnchanged(): void
    {
        $r = $this->intern(30, 'fixed');

        $r->name = 'mutated';
        $this->expectException(AppendOnlyViolationException::class);
        try {
            $r->save();
        } finally {
            $reloaded = ImmutableDocRecord::getOne(30);
            self::assertNotNull($reloaded);
            self::assertSame('fixed', $reloaded->name, 'row must be untouched by a rejected update');
        }
    }

    public function testUpdateWhereThrows(): void
    {
        $this->intern(40, 'y');
        $this->expectException(AppendOnlyViolationException::class);
        ImmutableDocRecord::updateWhere(['name' => 'z'], WhereClause::match(['id' => 40]));
    }

    public function testUpdateByWhereThrows(): void
    {
        $r = $this->intern(50, 'w');
        $r->name = 'changed';
        $this->expectException(AppendOnlyViolationException::class);
        $r->updateByWhere(WhereClause::match(['id' => 50]));
    }

    public function testUpsertAllThrows(): void
    {
        // Rejected outright rather than only when it would update: the insert-vs-update choice is
        // per record at runtime, so it can never be relied on to insert.
        $a = new ImmutableDocRecord();
        $a->id = 60;
        $a->name = 'ua';
        $this->expectException(AppendOnlyViolationException::class);
        (new RecordSet([$a]))->upsertAll();
    }

    public function testUpsertAllByUniqueKeyThrows(): void
    {
        $a = new ImmutableDocRecord();
        $a->id = 70;
        $a->name = 'uk';
        $this->expectException(AppendOnlyViolationException::class);
        (new RecordSet([$a]))->upsertAllByUniqueKey('uk_doc_name');
    }

    // --- reaping: delete only what nothing points at ---------------------

    public function testDeleteUnreferencedSkipsHeldKeysAndReapsTheRest(): void
    {
        // The reaper's whole job in one statement: hand it candidates, get back the ones it could
        // actually remove.
        $this->intern(90, 'free');
        $this->intern(91, 'held');
        $this->pointAt(91);

        $reaped = ImmutableDocRecord::deleteUnreferenced([90, 91]);

        self::assertSame(1, $reaped, 'only the unreferenced one');
        self::assertNull(ImmutableDocRecord::getOne(90));
        self::assertNotNull(ImmutableDocRecord::getOne(91), 'a referenced row survives untouched');
    }

    public function testDeleteUnreferencedIsANoOpForAnEmptyOrAlreadyGoneSet(): void
    {
        self::assertSame(0, ImmutableDocRecord::deleteUnreferenced([]), 'no keys, no statement');
        self::assertSame(0, ImmutableDocRecord::deleteUnreferenced([424242]), 'a key that is not there');
    }

    public function testDeleteUnreferencedRefusesAnUndeclaredColumn(): void
    {
        $this->expectException(\Nandan108\Attrecord\Exception\SchemaException::class);
        $this->expectExceptionMessage('is not a declared column');
        ImmutableDocRecord::deleteUnreferenced([1], 'no_such_column');
    }

    public function testDeleteUnreferencedAliasesTheInnerTableOnASelfReference(): void
    {
        // The regression this exists for. Unaliased, the inner table shadows the outer one and the
        // correlation asks whether a row is its OWN parent — true of nobody — so the predicate
        // matches everything and the check silently checks nothing. That is only survivable where
        // every referring key is RESTRICT; against a CASCADE the same wrong predicate would take
        // the children with it.
        $root = new ImmutableTreeRecord();
        $root->id = 1;
        $child = new ImmutableTreeRecord();
        $child->id = 2;
        $child->parent_id = 1;
        $leaf = new ImmutableTreeRecord();
        $leaf->id = 3;
        (new RecordSet([$root, $child, $leaf]))->insertAll();

        $reaped = ImmutableTreeRecord::deleteUnreferenced([1, 3]);

        self::assertSame(1, $reaped, 'only the leaf — the root is still its child\'s parent');
        self::assertNotNull(ImmutableTreeRecord::getOne(1), 'the referenced root survives');
        self::assertNull(ImmutableTreeRecord::getOne(3));
    }

    // --- what the reader is told ----------------------------------------

    public function testTheMessageNamesTheRightMarkerAndDoesNotForbidDeleting(): void
    {
        // A reader hitting this is deciding what to do instead; "never update or delete" would send
        // the author of an Immutable Record away from delete(), which is exactly what they want.
        $this->intern(80, 'msg');

        try {
            ImmutableDocRecord::updateWhere(['name' => 'nope'], WhereClause::match(['id' => 80]));
            self::fail('updateWhere() must throw');
        } catch (AppendOnlyViolationException $e) {
            self::assertStringContainsString('is immutable (implements Immutable)', $e->getMessage());
            self::assertStringContainsString('Deleting IS permitted', $e->getMessage());
            self::assertStringNotContainsString('append-only', $e->getMessage());
        }
    }
}
