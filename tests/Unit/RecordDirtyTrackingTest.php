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

/** @psalm-suppress PropertyNotSetInConstructor */
final class RecordDirtyTrackingTest extends TestCase
{
    private CapturingDbSession $session;

    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        Record::setConnection(new Connection($this->session, new MysqlDialect()));
        TableSchema::clearCache();
    }

    public function testNewRecordIsAlwaysDirty(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';

        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isNew());
    }

    public function testHydratedRecordIsClean(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 1, 'name' => 'Alice', 'email' => null, 'active' => null]);

        $this->assertFalse($user->isDirty());
        $this->assertFalse($user->isNew());
    }

    public function testChangingFieldMarksDirty(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 1, 'name' => 'Alice', 'email' => null, 'active' => null]);
        $user->name = 'Bob';

        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));
    }

    public function testDirtyFieldsReturnsChangedColumns(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 1, 'name' => 'Alice', 'email' => null, 'active' => null]);
        $user->name = 'Bob';

        $dirty = $user->dirtyFields();
        $this->assertArrayHasKey('name', $dirty);
        $this->assertArrayNotHasKey('email', $dirty);
        $this->assertSame('Alice', $dirty['name'][0]); // snapshot
        $this->assertSame('Bob', $dirty['name'][1]);   // current
    }

    public function testSaveNewRecordGeneratesInsert(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->save();

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('INSERT INTO `attrecord_users`', $sql);
        $this->assertStringContainsString('`name`', $sql);
        $this->assertStringContainsString('`email`', $sql);
        $this->assertStringContainsString('VALUES', $sql);
        $this->assertContains('Alice', $this->session->lastParams() ?? []);
        $this->assertContains('alice@example.com', $this->session->lastParams() ?? []);
    }

    public function testSaveNewRecordAssignsPkAndClearsIsNew(): void
    {
        $this->session->setNextInsertId(7);
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->save();

        $this->assertSame(7, $user->id);
        $this->assertFalse($user->isNew());
    }

    public function testSaveExistingRecordGeneratesUpdateWithOnlyDirtyColumns(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 5, 'name' => 'Alice', 'email' => 'a@b.com', 'active' => null]);
        $user->name = 'Bob';
        $user->save();

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('UPDATE `attrecord_users`', $sql);
        $this->assertStringContainsString('`name` = ?', $sql);
        $this->assertStringNotContainsString('`email`', $sql);
    }

    public function testSaveCleanRecordReturnsFalseWithNoSql(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 5, 'name' => 'Alice', 'email' => null, 'active' => null]);
        $this->session->reset();

        $saved = $user->save();

        $this->assertFalse($saved);
        $this->assertNull($this->session->lastSql());
    }

    public function testSaveMarksRecordClean(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 5, 'name' => 'Alice', 'email' => null, 'active' => null]);
        $user->name = 'Bob';
        $user->save();

        $this->assertFalse($user->isDirty());
    }

    public function testDeleteGeneratesDeleteSql(): void
    {
        $user = UserRecord::hydrateFromArray(['id' => 5, 'name' => 'Alice', 'email' => null, 'active' => null]);
        $user->delete();

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('DELETE FROM `attrecord_users`', $sql);
        $this->assertStringContainsString('`id` = ?', $sql);
        $this->assertContains(5, $this->session->lastParams() ?? []);
    }

    public function testLastInsertIdAdvancesPerExec(): void
    {
        $this->session->setNextInsertId(10);

        $u1 = new UserRecord();
        $u1->name = 'A';
        $u1->save();
        $this->assertSame(10, $u1->id);

        $u2 = new UserRecord();
        $u2->name = 'B';
        $u2->save();
        $this->assertSame(11, $u2->id);
    }
}
