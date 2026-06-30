<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * Covers Record methods whose SQL/behaviour can be asserted without a live DB, via the
 * CapturingDbSession.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class RecordMethodsTest extends TestCase
{
    private CapturingDbSession $session;

    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        Record::setConnection(new Connection($this->session, new MysqlDialect()));
        TableSchema::clearCache();
    }

    public function testUpdateByUniqueKeyUsesPkWhenSet(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 5, 'name' => 'Alice', 'email' => 'a@x.com', 'active' => null]);
        $user->name = 'Alicia';

        $affected = $user->updateByUniqueKey();

        $this->assertSame(1, $affected);
        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('UPDATE `attrecord_users` SET', $sql);
        $this->assertStringContainsString('`name` = ?', $sql);
        $this->assertStringContainsString('WHERE `id` = ?', $sql);
    }

    public function testUpdateByWhereBuildsSetFromProperties(): void
    {
        $user = new UserRecord();
        $user->name = 'Bob';

        $user->updateByWhere('`id` > ?', [0]);

        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('UPDATE `attrecord_users` SET `name` = ?', $sql);
        $this->assertStringContainsString('WHERE `id` > ?', $sql);
    }

    public function testWhereInTuplesBuildsRowValueInClause(): void
    {
        UserRecord::whereInTuples(['name', 'email'], [['a', 'x@x'], ['b', 'y@y']]);

        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('`name`', $sql);
        $this->assertStringContainsString('`email`', $sql);
        $this->assertStringContainsString('IN', $sql);
    }

    public function testToRawArrayExportsAllColumnsAsScalars(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 3, 'name' => 'Carol', 'email' => null, 'active' => 1]);

        $raw = $user->toRawArray();

        $this->assertSame(['id', 'name', 'email', 'active'], array_keys($raw));
        $this->assertSame('Carol', $raw['name']);
        $this->assertNull($raw['email']);
    }

    public function testHydrateFromArrayProducesACleanRecord(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 1, 'name' => 'Dora', 'email' => 'd@x', 'active' => 1]);

        $this->assertFalse($user->isNew());
        $this->assertFalse($user->isDirty());
        $this->assertSame('Dora', $user->name);
        $this->assertTrue($user->active);
    }

    public function testDirtyFieldsReportsOldAndNew(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 1, 'name' => 'Eve', 'email' => null, 'active' => null]);
        $user->name = 'Evelyn';

        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));

        $dirty = $user->dirtyFields();
        $this->assertArrayHasKey('name', $dirty);
        $this->assertSame(['Eve', 'Evelyn'], $dirty['name']);
    }

    public function testMarkCleanResetsDirtyState(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 1, 'name' => 'Fay', 'email' => null, 'active' => null]);
        $user->name = 'Faye';
        $this->assertTrue($user->isDirty());

        $user->markClean();
        $this->assertFalse($user->isDirty());
    }

    public function testGetOneOrNewReturnsNewRecordWhenMissing(): void
    {
        // CapturingDbSession::fetchOne() returns null → no row found.
        $user = UserRecord::getOneOrNew(99);

        $this->assertTrue($user->isNew());
        $this->assertSame(99, $user->id);
    }

    public function testNewWithAssignsAttributes(): void
    {
        $user = UserRecord::newWith(['name' => 'Zoe', 'email' => 'z@x']);

        $this->assertSame('Zoe', $user->name);
        $this->assertSame('z@x', $user->email);
        $this->assertTrue($user->isNew());
    }
}
