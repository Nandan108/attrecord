<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Pgsql;

use Nandan108\Attrecord\Exception\RecordNotFoundException;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase;

final class RecordCrudPgsqlTest extends PgsqlIntegrationTestCase
{
    protected static function createSchema(): void
    {
        static::$pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS attrecord_users (
                id     BIGSERIAL    PRIMARY KEY,
                name   VARCHAR(100) NOT NULL,
                email  VARCHAR(200) DEFAULT NULL,
                active BOOLEAN      DEFAULT NULL
            )
            SQL);
    }

    protected static function truncateTables(): void
    {
        static::$pdo->exec('TRUNCATE TABLE attrecord_users RESTART IDENTITY CASCADE');
    }

    // -----------------------------------------------------------------
    // INSERT / getOne
    // -----------------------------------------------------------------

    public function testInsertAssignsPk(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->save();

        $this->assertNotNull($user->id);
        $this->assertGreaterThan(0, $user->id);
        $this->assertFalse($user->isNew());
    }

    public function testGetOneReturnsHydratedRecord(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->save();

        $found = UserRecord::getOne((int) $user->id);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
        $this->assertSame('Alice', $found->name);
        $this->assertSame('alice@example.com', $found->email);
        $this->assertFalse($found->isDirty());
    }

    public function testGetOneReturnsNullForMissingRecord(): void
    {
        $this->assertNull(UserRecord::getOne(99999));
    }

    public function testGetOneOrFailThrowsForMissingRecord(): void
    {
        $this->expectException(RecordNotFoundException::class);
        UserRecord::getOneOrFail(99999);
    }

    public function testGetOneOrNewReturnsFreshRecordWithPk(): void
    {
        $record = UserRecord::getOneOrNew(99999);

        $this->assertSame(99999, $record->id);
        $this->assertTrue($record->isNew());
    }

    // -----------------------------------------------------------------
    // UPDATE
    // -----------------------------------------------------------------

    public function testUpdatePersistsOnlyDirtyColumns(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->save();

        $user->name = 'Alicia';
        $user->save();

        $found = UserRecord::getOne((int) $user->id);
        $this->assertNotNull($found);
        $this->assertSame('Alicia', $found->name);
        $this->assertSame('alice@example.com', $found->email);
    }

    public function testSaveReturnsFalseWhenClean(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->save();

        $this->assertFalse($user->save()->_saved);
    }

    public function testReload(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->save();

        // Simulate an external update then verify reload picks it up
        static::$pdo->exec("UPDATE attrecord_users SET name='Updated' WHERE id={$user->id}");
        $user->reload();

        $fresh = UserRecord::getOne((int) $user->id);
        $this->assertNotNull($fresh);
        $this->assertSame('Updated', $fresh->name);
        $this->assertFalse($fresh->isDirty());
    }

    // -----------------------------------------------------------------
    // DELETE
    // -----------------------------------------------------------------

    public function testDeleteRemovesRecord(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->save();
        $id = (int) $user->id;

        $user->delete();

        $this->assertNull(UserRecord::getOne($id));
        $this->assertTrue($user->isNew());
    }

    // -----------------------------------------------------------------
    // find / findOne / countWhere / deleteWhere
    // -----------------------------------------------------------------

    public function testFindReturnsAllMatchingRecords(): void
    {
        foreach (['Alice', 'Bob', 'Charlie'] as $name) {
            $u = new UserRecord();
            $u->name = $name;
            $u->save();
        }

        $all = UserRecord::find();
        $this->assertCount(3, $all);

        $filtered = UserRecord::find('name = ?', ['Bob']);
        $this->assertCount(1, $filtered);
        $this->assertSame('Bob', $filtered->first()?->name);
    }

    public function testFindOne(): void
    {
        $u = new UserRecord();
        $u->name = 'Alice';
        $u->save();

        $found = UserRecord::findOne('name = ?', ['Alice']);
        $this->assertNotNull($found);
        $this->assertSame('Alice', $found->name);

        $this->assertNull(UserRecord::findOne('name = ?', ['Nobody']));
    }

    public function testCountWhere(): void
    {
        foreach (['Alice', 'Bob'] as $name) {
            $u = new UserRecord();
            $u->name = $name;
            $u->save();
        }

        $this->assertSame(2, UserRecord::countWhere('id > 0'));
        $this->assertSame(1, UserRecord::countWhere('name = ?', ['Alice']));
    }

    public function testDeleteWhere(): void
    {
        foreach (['Alice', 'Bob', 'Charlie'] as $name) {
            $u = new UserRecord();
            $u->name = $name;
            $u->save();
        }

        $deleted = UserRecord::deleteWhere('name != ?', ['Alice']);
        $this->assertSame(2, $deleted);
        $this->assertSame(1, UserRecord::countWhere('id > 0'));
    }

    // -----------------------------------------------------------------
    // Booleans (PG returns 't'/'f' instead of 1/0)
    // -----------------------------------------------------------------

    public function testBooleanColumnRoundtrips(): void
    {
        $user = new UserRecord();
        $user->name = 'Alice';
        $user->active = true;
        $user->save();

        $found = UserRecord::getOne((int) $user->id);
        $this->assertNotNull($found);
        $this->assertTrue($found->active);

        $found->active = false;
        $found->save();

        $found2 = UserRecord::getOne((int) $user->id);
        $this->assertNotNull($found2);
        $this->assertFalse($found2->active);
    }

    // -----------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------

    public function testTransactionalCommit(): void
    {
        $user = new UserRecord();
        $user->name = 'Committed';

        UserRecord::transactional(function () use ($user): void {
            $user->save();
        });

        $found = UserRecord::getOne((int) $user->id);
        $this->assertNotNull($found);
        $this->assertSame('Committed', $found->name);
    }

    public function testTransactionalRollbackOnException(): void
    {
        try {
            UserRecord::transactional(function (): void {
                $u = new UserRecord();
                $u->name = 'Rolled Back';
                $u->save();
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, UserRecord::countWhere('id > 0'));
    }
}
