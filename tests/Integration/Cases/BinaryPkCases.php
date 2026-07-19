<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Exception\RecordSaveException;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\BinaryPkRecord;
use Nandan108\Attrecord\Tests\Fixtures\MintedPkTimestampRecord;

/**
 * Shared BINARY(16) / BYTEA (non-autoincrement, application-minted) primary-key cases.
 *
 * Mirrors a UUIDv7-as-PK use case: the application generates the raw 16-byte binary ID and
 * assigns it before save(); attrecord must persist it as-is and never backfill from
 * lastInsertId(). On PostgreSQL this exercises the BinaryParam binding path (raw bytes bound
 * as a bytea LOB) and the bytea read path (stream → raw bytes).
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait BinaryPkCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [BinaryPkRecord::class, MintedPkTimestampRecord::class];
    }

    public function testInsertAllStampsCreatedAtOnMintedPk(): void
    {
        // Regression: insertAll() is insert-only, so a minted-PK (non-null) record is still new and
        // must get #[CreatedAt] stamped — previously skipped because the PK was non-null.
        $rec = new MintedPkTimestampRecord();
        $rec->id = 987654321;
        $rec->name = 'ledger-row';

        (new RecordSet([$rec]))->insertAll();

        $this->assertNotNull($rec->created_at, 'insertAll stamps created_at in-memory');

        $reloaded = MintedPkTimestampRecord::getOne(987654321);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->created_at, 'insertAll persists created_at for a minted PK');
    }

    public function testSinglePkSaveDoesNotOverwriteApplicationMintedId(): void
    {
        $uuid = random_bytes(16);

        $record = new BinaryPkRecord();
        $record->id = $uuid;
        $record->name = 'app-minted';
        $record->save();

        $this->assertSame($uuid, $record->id, 'application-set PK must survive save()');
        $this->assertFalse($record->isNew());

        $reloaded = BinaryPkRecord::getOne($uuid);
        $this->assertNotNull($reloaded);
        $this->assertSame($uuid, $reloaded->id);
        $this->assertSame('app-minted', $reloaded->name);
    }

    public function testBulkSaveAllPreservesApplicationMintedIds(): void
    {
        $uuids = [
            random_bytes(16),
            random_bytes(16),
            random_bytes(16),
        ];

        $records = [];
        foreach ($uuids as $i => $uuid) {
            $r = new BinaryPkRecord();
            $r->id = $uuid;
            $r->name = "row-{$i}";
            $records[] = $r;
        }
        $set = new RecordSet($records);

        $set->saveAll();

        // Each record keeps its application-minted ID; nothing is overwritten by
        // lastInsertId() arithmetic.
        foreach ($set as $i => $r) {
            $this->assertSame($uuids[$i], $r->id);
            $this->assertFalse($r->isNew());
        }

        // All three rows reload correctly by binary PK lookup.
        foreach ($uuids as $i => $uuid) {
            $reloaded = BinaryPkRecord::getOne($uuid);
            $this->assertNotNull($reloaded, "row {$i} must reload");
            $this->assertSame("row-{$i}", $reloaded->name);
        }
    }

    public function testInsertAllPersistsApplicationMintedIdsAsPlainInsert(): void
    {
        $uuids = [random_bytes(16), random_bytes(16), random_bytes(16)];

        $records = [];
        foreach ($uuids as $i => $uuid) {
            $r = new BinaryPkRecord();
            $r->id = $uuid;
            $r->name = "ins-{$i}";
            $records[] = $r;
        }
        $set = new RecordSet($records);

        // The generated statement is a single plain INSERT — never INSERT IGNORE / ON CONFLICT —
        // and carries the minted PK column, so a collision would surface rather than be swallowed.
        $sql = $set->buildInsertAllSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsStringIgnoringCase('insert into', $sql);
        $this->assertStringNotContainsStringIgnoringCase('ignore', $sql);
        $this->assertStringNotContainsStringIgnoringCase('on conflict', $sql);

        $result = $set->insertAll();

        $this->assertNotNull($result);
        $this->assertSame(3, $result->inserted);
        $this->assertSame(0, $result->updated);

        foreach ($set as $i => $r) {
            $this->assertSame($uuids[$i], $r->id, 'minted PK must survive insertAll()');
            $this->assertFalse($r->isNew());
        }
        foreach ($uuids as $i => $uuid) {
            $reloaded = BinaryPkRecord::getOne($uuid);
            $this->assertNotNull($reloaded, "row {$i} must reload");
            $this->assertSame("ins-{$i}", $reloaded->name);
        }
    }

    public function testInsertAllThrowsOnDuplicatePkAndDoesNotSwallow(): void
    {
        $dupe = random_bytes(16);

        $existing = new BinaryPkRecord();
        $existing->id = $dupe;
        $existing->name = 'original';
        $existing->save();

        $fresh = random_bytes(16);
        $collision = new BinaryPkRecord();
        $collision->id = $dupe;              // collides with the row already stored
        $collision->name = 'attempted-overwrite';
        $other = new BinaryPkRecord();
        $other->id = $fresh;
        $other->name = 'sibling';

        $set = new RecordSet([$collision, $other]);

        // A duplicate PK must surface as an error (append-only semantics), never be silently
        // ignored or update the existing row the way saveAll()'s keyed-upsert path would.
        $threw = false;
        try {
            $set->insertAll();
        } catch (RecordSaveException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'insertAll() must throw on a duplicate PK');

        // The pre-existing row is untouched — not overwritten by the colliding record.
        $reloaded = BinaryPkRecord::getOne($dupe);
        $this->assertNotNull($reloaded);
        $this->assertSame('original', $reloaded->name);
    }

    public function testInsertAllOnEmptySetReturnsNull(): void
    {
        $this->assertNull((new RecordSet([]))->insertAll());
    }

    public function testUpdateOnExistingBinaryPkRecordWorks(): void
    {
        $uuid = random_bytes(16);

        $record = new BinaryPkRecord();
        $record->id = $uuid;
        $record->name = 'initial';
        $record->save();

        $record->name = 'updated';
        $record->save();

        $reloaded = BinaryPkRecord::getOne($uuid);
        $this->assertNotNull($reloaded);
        $this->assertSame('updated', $reloaded->name);
    }

    public function testDeleteByBinaryPkWorks(): void
    {
        $uuid = random_bytes(16);

        $record = new BinaryPkRecord();
        $record->id = $uuid;
        $record->name = 'doomed';
        $record->save();

        $record->delete();

        $this->assertNull(BinaryPkRecord::getOne($uuid));
    }
}
