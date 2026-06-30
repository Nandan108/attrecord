<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\SaveResult;
use Nandan108\Attrecord\UpsertSql;
use PHPUnit\Framework\TestCase;

/** Covers the small readonly value objects. */
final class ValueObjectsTest extends TestCase
{
    public function testBinaryParamHoldsBytesAndStringifies(): void
    {
        $bytes = random_bytes(16);
        $param = new BinaryParam($bytes);

        $this->assertSame($bytes, $param->bytes);
        $this->assertSame($bytes, (string) $param);
        $this->assertInstanceOf(\Stringable::class, $param);
    }

    public function testSaveResultExposesCountsAndTotal(): void
    {
        $result = new SaveResult(inserted: 2, updated: 3, insertedIds: [1, 2]);

        $this->assertSame(2, $result->inserted);
        $this->assertSame(3, $result->updated);
        $this->assertSame([1, 2], $result->insertedIds);
        $this->assertSame(5, $result->total());
    }

    public function testSaveResultDefaultsInsertedIdsToEmpty(): void
    {
        $result = new SaveResult(1, 0);

        $this->assertSame([], $result->insertedIds);
        $this->assertSame(1, $result->total());
    }

    public function testUpsertSqlCarriesThreeStatements(): void
    {
        $upsert = new UpsertSql('INSERT …', 'SELECT … FOR UPDATE', 'UPDATE …');
        $this->assertSame('INSERT …', $upsert->create);
        $this->assertSame('SELECT … FOR UPDATE', $upsert->lock);
        $this->assertSame('UPDATE …', $upsert->update);

        $insertOnly = new UpsertSql('INSERT …', 'SELECT …', null);
        $this->assertNull($insertOnly->update);
    }
}
