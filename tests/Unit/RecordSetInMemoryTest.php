<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * In-memory RecordSet behaviour that needs no database: last(), toArraySet(), and the
 * ArrayAccess offset-validation branches.
 *
 * @template-covariant T of \Nandan108\Attrecord\Record
 */
final class RecordSetInMemoryTest extends TestCase
{
    /** @return RecordSet<UserRecord> */
    private function makeSet(int $n): RecordSet
    {
        $records = [];
        for ($i = 1; $i <= $n; ++$i) {
            $u = new UserRecord();
            $u->id = $i;
            $u->name = "u{$i}";
            $records[] = $u;
        }

        return new RecordSet($records);
    }

    public function testLastReturnsLastRecordOrNull(): void
    {
        $this->assertNull((new RecordSet([]))->last());

        $last = $this->makeSet(3)->last();
        $this->assertInstanceOf(UserRecord::class, $last);
        $this->assertSame('u3', $last->name);
    }

    public function testToArraySetMapsEachRecordToRawArray(): void
    {
        $arr = $this->makeSet(2)->toArraySet();

        $this->assertCount(2, $arr);
        $this->assertArrayHasKey('name', $arr[0]);
        $this->assertSame('u1', $arr[0]['name']);
    }

    public function testOffsetGetRejectsNonIntegerOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeSet(1)->offsetGet('nope');
    }

    public function testOffsetGetRejectsNegativeOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeSet(1)->offsetGet(-1);
    }

    public function testOffsetGetRejectsOutOfBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeSet(1)->offsetGet(5);
    }

    public function testOffsetSetRejectsGap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeSet(1)->offsetSet(5, new UserRecord());
    }

    public function testOffsetSetRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeSet(1)->offsetSet(-1, new UserRecord());
    }
}
