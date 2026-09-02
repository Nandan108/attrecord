<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Exception\AppendOnlyViolationException;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\ImmutableDocRecord;
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
        return [ImmutableDocRecord::class];
    }

    private function intern(int $id, string $name): ImmutableDocRecord
    {
        $r = new ImmutableDocRecord();
        $r->id = $id;
        $r->name = $name;
        (new RecordSet([$r]))->insertAll();

        return $r;
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
