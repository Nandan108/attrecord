<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\BinaryPkRecord;

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
        return [BinaryPkRecord::class];
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
