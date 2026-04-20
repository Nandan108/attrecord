<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Pgsql;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\PostRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase;

final class RecordSetPgsqlTest extends PgsqlIntegrationTestCase
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

        static::$pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS attrecord_posts (
                id      BIGSERIAL    PRIMARY KEY,
                user_id BIGINT       NOT NULL REFERENCES attrecord_users(id),
                title   VARCHAR(200) NOT NULL,
                body    TEXT         DEFAULT NULL
            )
            SQL);
    }

    protected static function truncateTables(): void
    {
        // CASCADE handles FK constraint order automatically
        static::$pdo->exec('TRUNCATE TABLE attrecord_posts, attrecord_users RESTART IDENTITY CASCADE');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeUser(string $name): UserRecord
    {
        $u = new UserRecord();
        $u->name = $name;
        $u->save();

        return $u;
    }

    private function makePost(int $userId, string $title): PostRecord
    {
        $p = new PostRecord();
        $p->user_id = $userId;
        $p->title = $title;
        $p->save();

        return $p;
    }

    // -----------------------------------------------------------------
    // saveAll — batch INSERT (no PK, ON CONFLICT path not used)
    // -----------------------------------------------------------------

    public function testSaveAllInsertsNewRecords(): void
    {
        $u1 = new UserRecord();
        $u1->name = 'Alice';
        $u2 = new UserRecord();
        $u2->name = 'Bob';
        $u3 = new UserRecord();
        $u3->name = 'Charlie';

        $set = new RecordSet([$u1, $u2, $u3]);
        $saved = $set->saveAll();

        $this->assertTrue($saved);
        $this->assertSame(3, UserRecord::countWhere('id > 0'));
    }

    public function testSaveAllMarksRecordsClean(): void
    {
        $u1 = new UserRecord();
        $u1->name = 'Alice';
        $u2 = new UserRecord();
        $u2->name = 'Bob';

        $set = new RecordSet([$u1, $u2]);
        $set->saveAll();

        $this->assertFalse($u1->isDirty());
        $this->assertFalse($u2->isDirty());
    }

    public function testSaveAllReturnsFalseWhenAllClean(): void
    {
        $u = $this->makeUser('Alice');
        $set = new RecordSet([$u]);

        $this->assertFalse($set->saveAll());
    }

    // -----------------------------------------------------------------
    // saveAll — 3-step upsert (keyed records, PG ON CONFLICT DO NOTHING)
    // -----------------------------------------------------------------

    public function testSaveAllUpsertsKeyedRecords(): void
    {
        $alice = $this->makeUser('Alice');
        $bob = $this->makeUser('Bob');

        // Dirty both and save via saveAll upsert path
        $alice->name = 'Alice Updated';
        $bob->name = 'Bob Updated';

        $set = new RecordSet([$alice, $bob]);
        $set->saveAll();

        $found1 = UserRecord::getOne((int) $alice->id);
        $found2 = UserRecord::getOne((int) $bob->id);

        $this->assertSame('Alice Updated', $found1?->name);
        $this->assertSame('Bob Updated', $found2?->name);
    }

    public function testBuildSaveAllSqlGeneratesPgsqlSyntax(): void
    {
        $alice = $this->makeUser('Alice');
        $alice->name = 'Alice Updated';

        $set = new RecordSet([$alice]);
        $upsert = $set->buildSaveAllSql();

        $this->assertNotNull($upsert);
        $this->assertStringContainsString('INSERT INTO "attrecord_users"', $upsert->create);
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $upsert->create);
        $this->assertStringContainsString('FOR UPDATE', $upsert->lock);
        $this->assertNotNull($upsert->update);
        $this->assertStringContainsString('UPDATE "attrecord_users"', $upsert->update);
    }

    // -----------------------------------------------------------------
    // deleteAll
    // -----------------------------------------------------------------

    public function testDeleteAllRemovesAllRecords(): void
    {
        $u1 = $this->makeUser('Alice');
        $u2 = $this->makeUser('Bob');

        $set = new RecordSet([$u1, $u2]);
        $deleted = $set->deleteAll();

        $this->assertSame(2, $deleted);
        $this->assertSame(0, UserRecord::countWhere('id > 0'));
    }

    // -----------------------------------------------------------------
    // with() — OneToMany / ManyToOne
    // -----------------------------------------------------------------

    public function testWithOneToMany(): void
    {
        $alice = $this->makeUser('Alice');
        $bob = $this->makeUser('Bob');

        $this->makePost((int) $alice->id, 'Post A1');
        $this->makePost((int) $alice->id, 'Post A2');
        $this->makePost((int) $bob->id, 'Post B1');

        $users = UserRecord::find()->with('posts');

        /** @var array<string, UserRecord> $byName */
        $byName = $users->recordsByKey('name');
        $this->assertInstanceOf(RecordSet::class, $byName['Alice']->posts);
        $this->assertInstanceOf(RecordSet::class, $byName['Bob']->posts);
        $this->assertCount(2, $byName['Alice']->posts);
        $this->assertCount(1, $byName['Bob']->posts);
    }

    public function testWithManyToOne(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post 1');
        $this->makePost((int) $alice->id, 'Post 2');

        $posts = PostRecord::find()->with('user');

        foreach ($posts as $post) {
            $this->assertInstanceOf(UserRecord::class, $post->user);
            $this->assertSame('Alice', $post->user->name);
        }
    }
}
