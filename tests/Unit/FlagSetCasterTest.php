<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Caster\BitmaskCaster;
use Nandan108\Attrecord\Caster\SetCaster;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\AccessRight;
use Nandan108\Attrecord\Tests\Fixtures\BitmaskRecord;
use Nandan108\Attrecord\Tests\Fixtures\FlagDupBitEnum;
use Nandan108\Attrecord\Tests\Fixtures\SetFlagsRecord;
use Nandan108\Attrecord\Tests\Fixtures\StockConcern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** String-backed enum — BitmaskCaster must reject it (needs int backing). */
enum FlagBadStringEnum: string
{
    case A = 'a';
}

/** Int-backed but a case is not a power of two — BitmaskCaster must reject. */
enum FlagNotPow2Enum: int
{
    case A = 1;
    case B = 3;
}

/** Int-backed — SetCaster must reject (needs string backing). */
enum SetBadIntEnum: int
{
    case A = 1;
}

/** String-backed but a member contains a comma — SetCaster must reject. */
enum SetCommaEnum: string
{
    case A = 'a,b';
}

#[CoversClass(BitmaskCaster::class)]
#[CoversClass(SetCaster::class)]
final class FlagSetCasterTest extends TestCase
{
    private static function bitmaskCol(): ColumnDefinition
    {
        return TableSchema::fromClass(BitmaskRecord::class)->columns['concerns'];
    }

    private static function setCol(): ColumnDefinition
    {
        return TableSchema::fromClass(SetFlagsRecord::class)->columns['rights'];
    }

    // ---- BitmaskCaster: construction validation ----

    public function testBitmaskRejectsNonIntBackedEnum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('int-backed');
        new BitmaskCaster(FlagBadStringEnum::class);
    }

    public function testBitmaskRejectsNonPowerOfTwoCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('power of two');
        new BitmaskCaster(FlagNotPow2Enum::class);
    }

    public function testBitmaskRejectsDuplicateBit(): void
    {
        // The fixture declares two enum cases sharing a value, which PHP 8.1 rejects at compile time —
        // so skip there (the fixture is PSR-4-lazy and never loads on 8.1). On 8.2+ the duplicate check
        // is deferred to from()/tryFrom(), so cases() reaches the caster and the guard fires.
        if (\PHP_VERSION_ID < 80200) {
            self::markTestSkipped('PHP 8.1 rejects duplicate enum values at compile time.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bit');
        new BitmaskCaster(FlagDupBitEnum::class);
    }

    // ---- BitmaskCaster: encode/decode ----

    public function testBitmaskToDbOrsMembersOrderAndDuplicateIndependent(): void
    {
        $c = new BitmaskCaster(StockConcern::class);
        $col = self::bitmaskCol();
        self::assertSame(9, $c->toDb([StockConcern::NoCost, StockConcern::Deficit], $col));
        // reordered + duplicated → same mask (canonical)
        self::assertSame(9, $c->toDb([StockConcern::Deficit, StockConcern::NoCost, StockConcern::Deficit], $col));
        self::assertSame(0, $c->toDb([], $col));
        self::assertSame(15, $c->toDb(StockConcern::cases(), $col));
    }

    public function testBitmaskFromDbDecomposesInDeclarationOrder(): void
    {
        $c = new BitmaskCaster(StockConcern::class);
        $col = self::bitmaskCol();
        self::assertSame(
            ['Deficit', 'NoCost'],
            array_map(static fn (\BackedEnum $s): string => $s->name, $c->fromDb(9, [], $col)),
        );
        self::assertSame([], $c->fromDb(0, [], $col));
        // Unknown/stale bits (16, 32) are ignored, known ones still decoded.
        self::assertSame(
            ['Overstock'],
            array_map(static fn (\BackedEnum $s): string => $s->name, $c->fromDb(2 | 16 | 32, [], $col)),
        );
    }

    public function testBitmaskToDbRejectsWrongElementType(): void
    {
        $c = new BitmaskCaster(StockConcern::class);
        $this->expectException(\InvalidArgumentException::class);
        $c->toDb([AccessRight::Read], self::bitmaskCol());
    }

    // ---- SetCaster: construction validation ----

    public function testSetRejectsNonStringBackedEnum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string-backed');
        new SetCaster(SetBadIntEnum::class);
    }

    public function testSetRejectsMemberContainingComma(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('comma');
        new SetCaster(SetCommaEnum::class);
    }

    // ---- SetCaster: encode/decode ----

    public function testSetToDbJoinsInDeclarationOrderDeduped(): void
    {
        $c = new SetCaster(AccessRight::class);
        $col = self::setCol();
        self::assertSame('read,admin', $c->toDb([AccessRight::Admin, AccessRight::Read], $col));
        self::assertSame('read,admin', $c->toDb([AccessRight::Read, AccessRight::Admin, AccessRight::Read], $col));
        self::assertSame('', $c->toDb([], $col));
    }

    public function testSetFromDbSplitsInDeclarationOrder(): void
    {
        $c = new SetCaster(AccessRight::class);
        $col = self::setCol();
        self::assertSame(
            ['Read', 'Admin'],
            array_map(static fn (\BackedEnum $r): string => $r->name, $c->fromDb('admin,read', [], $col)),
        );
        self::assertSame([], $c->fromDb('', [], $col));
    }

    public function testSetEnumValuesDerivedFromCases(): void
    {
        self::assertSame(['read', 'write', 'admin'], (new SetCaster(AccessRight::class))->enumValues());
    }
}
