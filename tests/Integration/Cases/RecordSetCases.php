<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\DdlGeneratedColumnRecord;
use Nandan108\Attrecord\Tests\Fixtures\PostRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;

/**
 * Shared RecordSet cases (saveAll batch insert/upsert, deleteAll, with() eager loading,
 * ArrayAccess/Iterator/Countable), run against both MySQL and PostgreSQL.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait RecordSetCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [UserRecord::class, PostRecord::class, DdlGeneratedColumnRecord::class];
    }

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
        $this->assertSame(3, UserRecord::countWhere('id > 0'));
        $this->assertCount(3, $result->insertedIds);
        $this->assertSame([1, 2, 3], $result->insertedIds);

        // saveAll() back-fills the generated auto-increment ids onto the records (like
        // save() does), in insertion order, as ints, and leaves them clean.
        $this->assertSame(1, $u1->id);
        $this->assertSame(2, $u2->id);
        $this->assertSame(3, $u3->id);
        $this->assertFalse($u1->isDirty());
        $this->assertFalse($u2->isDirty());
        $this->assertFalse($u3->isDirty());
    }

    public function testSaveAllUpsertSkipsGeneratedColumns(): void
    {
        // Regression: a keyed (existing) record carries its DB-generated column hydrated from a
        // prior load. The bulk upsert must NOT write that column back — both MySQL (error 1906)
        // and PostgreSQL reject a value for a GENERATED ALWAYS column. The plain-INSERT branch
        // already skipped generated columns; this guards the upsert (known-PK) branch too.
        $rec = new DdlGeneratedColumnRecord();
        $rec->scope_id = 7;
        $rec->value = 'before';
        $rec->save();
        $id = (int) $rec->id;

        // Reload so scope_key (STORED, = COALESCE(scope_id, 0)) is hydrated as a non-null value
        // — the state that previously leaked into the upsert column list.
        $loaded = DdlGeneratedColumnRecord::where('id', $id)->first();
        $this->assertNotNull($loaded);
        $this->assertSame(7, $loaded->scope_key);

        // Bulk upsert of the keyed record after touching a writable column must succeed.
        $loaded->value = 'after';
        $result = (new RecordSet([$loaded]))->saveAll();
        $this->assertNotNull($result);
        $this->assertSame(1, $result->updated);

        $reloaded = DdlGeneratedColumnRecord::where('id', $id)->first();
        $this->assertNotNull($reloaded);
        $this->assertSame('after', $reloaded->value);
        $this->assertSame(7, $reloaded->scope_key, 'generated column still reflects its expression');
    }

    public function testSaveAllUpsertClearsANullableColumnToNull(): void
    {
        // Regression: clearing a nullable column back to null on a keyed record must persist. The upsert
        // column list previously included only non-null values, so a value cleared to null was absent
        // from the CASE update and the old (non-null) value silently survived.
        $rec = new DdlGeneratedColumnRecord();
        $rec->scope_id = 3;
        $rec->value = 'x';
        $rec->save();
        $id = (int) $rec->id;

        $loaded = DdlGeneratedColumnRecord::where('id', $id)->first();
        $this->assertNotNull($loaded);
        $this->assertSame(3, $loaded->scope_id);

        $loaded->scope_id = null;
        $result = (new RecordSet([$loaded]))->saveAll();
        $this->assertNotNull($result);
        $this->assertSame(1, $result->updated);

        $reloaded = DdlGeneratedColumnRecord::where('id', $id)->first();
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->scope_id, 'a nullable column cleared to null must persist as null');
        $this->assertSame(0, $reloaded->scope_key, 'the generated column reflects the now-null source');
    }

    public function testSaveAllUpsertDoesNotClobberFieldsAnotherRecordSent(): void
    {
        // The controller shape: two DB rows, then a heterogeneous payload built as PARTIAL keyed
        // records — each carrying a DIFFERENT subset of fields, no prior load. saveAll() must update
        // each row's own fields and leave untouched any column that row never sent, even when a
        // *sibling* record in the same batch did send it (which pulls it into the batch column set).
        $a = new UserRecord();
        $a->name = 'Alice';
        $a->email = 'alice@example.com';
        $a->active = true;
        $a->save();
        $b = new UserRecord();
        $b->name = 'Bob';
        $b->email = 'bob@example.com';
        $b->active = false;
        $b->save();

        $pa = new UserRecord();
        $pa->id = $a->id;
        $pa->name = 'Alice2';
        $pa->email = 'alice2@example.com';   // A changes name + email (not active)

        $pb = new UserRecord();
        $pb->id = $b->id;
        $pb->name = 'Bob2';                  // B changes name only (email/active never sent)

        (new RecordSet([$pa, $pb]))->saveAll();

        $ra = UserRecord::getOne((int) $a->id);
        $rb = UserRecord::getOne((int) $b->id);
        $this->assertNotNull($ra);
        $this->assertNotNull($rb);

        // Sent fields applied…
        $this->assertSame('Alice2', $ra->name);
        $this->assertSame('alice2@example.com', $ra->email);
        $this->assertSame('Bob2', $rb->name);
        // …and fields a record never sent survive — including `email`, which the sibling A *did*
        // send (so it was in the batch column set) yet B must not have cleared.
        $this->assertTrue($ra->active, 'A.active must survive — A never sent it');
        $this->assertSame('bob@example.com', $rb->email, 'B.email must survive — B never sent it, though A did');
        $this->assertFalse($rb->active, 'B.active must survive — B never sent it');
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

        // Only u2 is dirty; u1 is persisted and clean.
        $set = new RecordSet([$u1, $u2]);
        $set->saveAll();

        // Total: Alice (from makeUser) + Bob (from saveAll) = 2.
        $this->assertSame(2, UserRecord::countWhere('id > 0'));
    }

    public function testSaveAllUpsertsKeyedRecords(): void
    {
        $alice = $this->makeUser('Alice');
        $bob = $this->makeUser('Bob');

        $alice->name = 'Alice Updated';
        $bob->name = 'Bob Updated';

        $set = new RecordSet([$alice, $bob]);
        $result = $set->saveAll();

        $this->assertNotNull($result);
        $this->assertSame(0, $result->inserted); // rows already existed — INSERT IGNORE skipped them
        $this->assertSame(2, $result->updated);  // both updated by the CASE UPDATE

        $this->assertSame('Alice Updated', UserRecord::getOne((int) $alice->id)?->name);
        $this->assertSame('Bob Updated', UserRecord::getOne((int) $bob->id)?->name);
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
        $this->assertSame(0, UserRecord::countWhere('id > 0'));
    }

    public function testDeleteAllEmptySetReturnsZero(): void
    {
        $set = new RecordSet([]);
        $this->assertSame(0, $set->deleteAll());
    }

    // -----------------------------------------------------------------
    // with() — OneToMany / ManyToOne / chained
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

    public function testWithChainedRelation(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A');

        // users → posts → user (roundtrip through both relations).
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
