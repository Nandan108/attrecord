<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\PostRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

final class RecordSetTest extends IntegrationTestCase
{
    protected static function createSchema(): void
    {
        static::$pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS `attrecord_users` (
                `id`     bigint unsigned NOT NULL AUTO_INCREMENT,
                `name`   varchar(100)    NOT NULL,
                `email`  varchar(200)    DEFAULT NULL,
                `active` tinyint(1)      DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        static::$pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS `attrecord_posts` (
                `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
                `user_id` bigint unsigned NOT NULL,
                `title`   varchar(200)    NOT NULL,
                `body`    text            DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                CONSTRAINT `fk_posts_user_id`
                    FOREIGN KEY (`user_id`) REFERENCES `attrecord_users` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    protected static function truncateTables(): void
    {
        static::$pdo->exec('TRUNCATE TABLE `attrecord_posts`');
        static::$pdo->exec('TRUNCATE TABLE `attrecord_users`');
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
    // saveAll — batch insert
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
        $result = $set->saveAll();

        $this->assertNotNull($result);
        $this->assertSame(3, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(3, UserRecord::countWhere('`id` > 0'));
        $this->assertCount(3, $result->insertedIds);
        $this->assertSame([1, 2, 3], $result->insertedIds);
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

    public function testSaveAllReturnsNullWhenAllClean(): void
    {
        $u = $this->makeUser('Alice');
        $set = new RecordSet([$u]);

        $this->assertNull($set->saveAll());
    }

    public function testSaveAllSkipsCleanRecords(): void
    {
        $u1 = $this->makeUser('Alice');
        $u2 = new UserRecord();
        $u2->name = 'Bob';

        // Only u2 is dirty; u1 is persisted and clean
        $set = new RecordSet([$u1, $u2]);
        $set->saveAll();

        // Total: Alice (from makeUser) + Bob (from saveAll) = 2
        $this->assertSame(2, UserRecord::countWhere('`id` > 0'));
    }

    public function testSaveAllEmptySetReturnsNull(): void
    {
        $set = new RecordSet([]);
        $this->assertNull($set->saveAll());
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
        $this->assertSame(0, UserRecord::countWhere('`id` > 0'));
    }

    public function testDeleteAllEmptySetReturnsZero(): void
    {
        $set = new RecordSet([]);
        $this->assertSame(0, $set->deleteAll());
    }

    // -----------------------------------------------------------------
    // with() — OneToMany
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
        $aliceLoaded = $byName['Alice'];
        $bobLoaded = $byName['Bob'];

        $this->assertInstanceOf(RecordSet::class, $aliceLoaded->posts);
        $this->assertInstanceOf(RecordSet::class, $bobLoaded->posts);
        $this->assertCount(2, $aliceLoaded->posts);
        $this->assertCount(1, $bobLoaded->posts);
    }

    public function testWithOneToManyEmptyRelation(): void
    {
        $this->makeUser('Alice'); // no posts

        $users = UserRecord::find()->with('posts');
        $alice = $users->first();
        $this->assertNotNull($alice);
        $this->assertInstanceOf(RecordSet::class, $alice->posts);
        $this->assertCount(0, $alice->posts);
    }

    // -----------------------------------------------------------------
    // with() — ManyToOne
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // with() — dot-notation chain
    // -----------------------------------------------------------------

    public function testWithChainedRelation(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A');

        // users → posts → user (roundtrip through both relations)
        $users = UserRecord::find()->with('posts.user');

        $first = $users->first();
        $this->assertNotNull($first);
        $this->assertInstanceOf(RecordSet::class, $first->posts);

        $post = $first->posts->first();
        $this->assertNotNull($post);
        $this->assertInstanceOf(UserRecord::class, $post->user);
    }

    // -----------------------------------------------------------------
    // ArrayAccess / Iterator / Countable
    // -----------------------------------------------------------------

    public function testCountable(): void
    {
        $this->makeUser('Alice');
        $this->makeUser('Bob');

        $set = UserRecord::find();
        $this->assertCount(2, $set);
    }

    public function testIterator(): void
    {
        $this->makeUser('Alice');
        $this->makeUser('Bob');

        $found = UserRecord::find();
        $names = [];
        foreach ($found as $user) {
            /** @psalm-suppress UnnecessaryVarAnnotation */
            /** @var UserRecord $user */
            $names[] = $user->name;
        }
        sort($names);

        $this->assertSame(['Alice', 'Bob'], $names);
    }

    public function testArrayAccess(): void
    {
        $this->makeUser('Alice');
        $this->makeUser('Bob');
        /** @psalm-suppress UnnecessaryVarAnnotation */
        /** @var RecordSet<UserRecord> $set */
        $set = UserRecord::find();
        $this->assertSame('Alice', $set[0]->name);
        $this->assertSame('Bob', $set[1]->name);
    }

    public function testOffsetSetAppend(): void
    {
        $u1 = $this->makeUser('Alice');
        $u2 = $this->makeUser('Bob');

        $set = new RecordSet([$u1]);
        $set[] = $u2;

        $this->assertCount(2, $set);
        $this->assertSame('Bob', $set[1]->name);
    }

    public function testOffsetUnset(): void
    {
        $u1 = $this->makeUser('Alice');
        $u2 = $this->makeUser('Bob');

        $set = new RecordSet([$u1, $u2]);
        unset($set[0]);

        $this->assertCount(1, $set);
        $this->assertSame('Bob', $set[0]->name);
    }
}
