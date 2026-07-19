<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Exception\AppendOnlyViolationException;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\AppendOnlyLedgerRecord;
use Nandan108\Attrecord\WhereClause;

/**
 * Shared cases for the {@see \Nandan108\Attrecord\AppendOnly} contract: write-once rows.
 *
 * Inserts (insertAll / new-record save) and reads (finders) must work; every update or delete
 * path must throw {@see AppendOnlyViolationException} and leave stored rows untouched.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait AppendOnlyCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [AppendOnlyLedgerRecord::class];
    }

    private function seed(int $id, string $name): AppendOnlyLedgerRecord
    {
        $r = new AppendOnlyLedgerRecord();
        $r->id = $id;
        $r->name = $name;
        (new RecordSet([$r]))->insertAll();

        return $r;
    }

    // --- allowed paths -------------------------------------------------

    public function testInsertAllAppendsAndStampsCreatedAt(): void
    {
        $a = new AppendOnlyLedgerRecord();
        $a->id = 1;
        $a->name = 'a';
        $b = new AppendOnlyLedgerRecord();
        $b->id = 2;
        $b->name = 'b';

        (new RecordSet([$a, $b]))->insertAll();

        $this->assertNotNull($a->created_at, 'insertAll stamps #[CreatedAt]');
        $this->assertNotNull(AppendOnlyLedgerRecord::getOne(1));
        $this->assertNotNull(AppendOnlyLedgerRecord::getOne(2));
    }

    public function testNewRecordSaveIsAllowedAppend(): void
    {
        $r = new AppendOnlyLedgerRecord();
        $r->id = 10;
        $r->name = 'single';
        $r->save(); // new record → INSERT → allowed

        $this->assertFalse($r->isNew());
        $this->assertNotNull(AppendOnlyLedgerRecord::getOne(10));
    }

    public function testFinderReadsAreAllowed(): void
    {
        $this->seed(20, 'readable');

        $this->assertNotNull(AppendOnlyLedgerRecord::getOne(20));
        $found = AppendOnlyLedgerRecord::find(WhereClause::match(['name' => 'readable']));
        $this->assertCount(1, $found);
    }

    // --- forbidden mutation paths -------------------------------------

    public function testSaveOnExistingRowThrowsAndLeavesRowUnchanged(): void
    {
        $r = $this->seed(30, 'immutable');

        $r->name = 'mutated';
        $this->expectException(AppendOnlyViolationException::class);
        try {
            $r->save();
        } finally {
            $reloaded = AppendOnlyLedgerRecord::getOne(30);
            $this->assertNotNull($reloaded);
            $this->assertSame('immutable', $reloaded->name, 'row must be untouched by a rejected update');
        }
    }

    public function testDeleteThrows(): void
    {
        $r = $this->seed(40, 'keep');

        try {
            $r->delete();
            $this->fail('delete() must throw on an append-only record');
        } catch (AppendOnlyViolationException) {
            $this->assertNotNull(AppendOnlyLedgerRecord::getOne(40), 'row must survive a rejected delete');
        }
    }

    public function testDeleteWhereThrows(): void
    {
        $this->seed(50, 'x');
        $this->expectException(AppendOnlyViolationException::class);
        AppendOnlyLedgerRecord::deleteWhere(WhereClause::match(['id' => 50]));
    }

    public function testUpdateWhereThrows(): void
    {
        $this->seed(60, 'y');
        $this->expectException(AppendOnlyViolationException::class);
        AppendOnlyLedgerRecord::updateWhere(['name' => 'z'], WhereClause::match(['id' => 60]));
    }

    public function testUpdateByWhereThrows(): void
    {
        $r = $this->seed(70, 'w');
        $r->name = 'changed';
        $this->expectException(AppendOnlyViolationException::class);
        $r->updateByWhere(WhereClause::match(['id' => 70]));
    }

    public function testUpsertAllThrows(): void
    {
        $a = new AppendOnlyLedgerRecord();
        $a->id = 80;
        $a->name = 'sa';
        $this->expectException(AppendOnlyViolationException::class);
        (new RecordSet([$a]))->upsertAll();
    }

    public function testDeprecatedSaveAllAliasAlsoThrows(): void
    {
        $a = new AppendOnlyLedgerRecord();
        $a->id = 81;
        $a->name = 'sa-alias';
        $this->expectException(AppendOnlyViolationException::class);
        /** @psalm-suppress DeprecatedMethod — asserting the deprecated alias still enforces the guard */
        (new RecordSet([$a]))->saveAll();
    }

    public function testDeleteAllThrows(): void
    {
        $r = $this->seed(90, 'da');
        $this->expectException(AppendOnlyViolationException::class);
        (new RecordSet([$r]))->deleteAll();
    }

    public function testUpsertAllByUniqueKeyThrows(): void
    {
        $a = new AppendOnlyLedgerRecord();
        $a->id = 100;
        $a->name = 'ua';
        $this->expectException(AppendOnlyViolationException::class);
        (new RecordSet([$a]))->upsertAllByUniqueKey('uk_name');
    }
}
