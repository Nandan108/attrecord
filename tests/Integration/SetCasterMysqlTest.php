<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Fixtures\AccessRight;
use Nandan108\Attrecord\Tests\Fixtures\SetFlagsRecord;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

/**
 * {@see \Nandan108\Attrecord\Caster\SetCaster} against a native MySQL `SET(...)` column. MySQL-only
 * by construction — `ColumnType::Set` throws at schema-build on PostgreSQL/SQLite, so there is no
 * portable counterpart to this suite (that is {@see BitmaskMysqlTest} et al.).
 *
 * @group mysql
 */
final class SetCasterMysqlTest extends IntegrationTestCase
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [SetFlagsRecord::class];
    }

    /**
     * @param array<\BackedEnum> $rights
     *
     * @return list<string>
     */
    private static function names(array $rights): array
    {
        return array_values(array_map(static fn (\BackedEnum $r): string => $r->name, $rights));
    }

    public function testRoundTripsASetColumnInDeclarationOrder(): void
    {
        $rec = new SetFlagsRecord();
        $rec->rights = [AccessRight::Admin, AccessRight::Read];
        $rec->save();

        $reloaded = SetFlagsRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(['Read', 'Admin'], self::names($reloaded->rights));
    }

    public function testEmptySetRoundTrips(): void
    {
        $rec = new SetFlagsRecord();
        $rec->rights = [];
        $rec->save();

        $reloaded = SetFlagsRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame([], $reloaded->rights);
    }

    public function testReorderingIsNotDirty(): void
    {
        $rec = new SetFlagsRecord();
        $rec->rights = [AccessRight::Read, AccessRight::Write];
        $rec->save();

        $reloaded = SetFlagsRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $reloaded->rights = array_reverse($reloaded->rights);
        $this->assertFalse($reloaded->isDirty('rights'), 'reordering a SET must not dirty it');
    }
}
