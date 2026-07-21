<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\Exception\RecordDeleteException;
use Nandan108\Attrecord\Exception\RecordNotFoundException;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\CommentRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlGeneratedColumnRecord;
use Nandan108\Attrecord\Tests\Fixtures\InsertDefaultRecord;
use Nandan108\Attrecord\Tests\Fixtures\PostRecord;
use Nandan108\Attrecord\Tests\Fixtures\PostTagPivot;
use Nandan108\Attrecord\Tests\Fixtures\TagRecord;
use Nandan108\Attrecord\Tests\Fixtures\TimestampedRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use Nandan108\Attrecord\WhereClause;

/**
 * Shared RecordSet cases (upsertAll batch insert/upsert, deleteAll, with() eager loading,
 * ArrayAccess/Iterator/Countable), run against both MySQL and PostgreSQL.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait RecordSetCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [
            UserRecord::class,
            PostRecord::class,
            DdlGeneratedColumnRecord::class,
            InsertDefaultRecord::class,
            TimestampedRecord::class,
            TagRecord::class,
            CommentRecord::class,
            PostTagPivot::class,
        ];
    }

    private function makeUser(string $name): UserRecord
    {
        $u = new UserRecord();
        $u->name = $name;
        $u->save();

        return $u;
    }

    /**
     * Read a column property dynamically so psalm can't narrow it to the literal last assigned —
     * the value is mutated in place by a read-back (save()/insertAll()/upsertAll()), which psalm
     * does not model.
     */
    private static function propValue(object $record, string $name): mixed
    {
        return $record->{$name};
    }

    private function makePost(int $userId, string $title): PostRecord
    {
        $p = new PostRecord();
        $p->user_id = $userId;
        $p->title = $title;
        $p->save();

        return $p;
    }

    private function makeTag(string $name): TagRecord
    {
        $t = new TagRecord();
        $t->name = $name;
        $t->save();

        return $t;
    }

    private function makeComment(int $postId, string $body): CommentRecord
    {
        $c = new CommentRecord();
        $c->post_id = $postId;
        $c->body = $body;
        $c->save();

        return $c;
    }

    private function linkPostTag(int $postId, int $tagId): void
    {
        $link = new PostTagPivot();
        $link->post_id = $postId;
        $link->tag_id = $tagId;
        $link->save();
    }

    // -----------------------------------------------------------------
    // upsertAll — batch insert
    // -----------------------------------------------------------------

    public function testUpsertAllInsertsNewRecords(): void
    {
        $u1 = new UserRecord();
        $u1->name = 'Alice';
        $u2 = new UserRecord();
        $u2->name = 'Bob';
        $u3 = new UserRecord();
        $u3->name = 'Charlie';

        $set = new RecordSet([$u1, $u2, $u3]);
        $result = $set->upsertAll();

        $this->assertNotNull($result);
        $this->assertSame(3, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(3, UserRecord::countWhere('id > 0'));
        $this->assertCount(3, $result->insertedIds);
        $this->assertSame([1, 2, 3], $result->insertedIds);

        // upsertAll() back-fills the generated auto-increment ids onto the records (like
        // save() does), in insertion order, as ints, and leaves them clean.
        $this->assertSame(1, $u1->id);
        $this->assertSame(2, $u2->id);
        $this->assertSame(3, $u3->id);
        $this->assertFalse($u1->isDirty());
        $this->assertFalse($u2->isDirty());
        $this->assertFalse($u3->isDirty());
    }

    public function testDeprecatedSaveAllAliasDelegatesToUpsertAll(): void
    {
        $u = new UserRecord();
        $u->name = 'AliasUser';

        /** @psalm-suppress DeprecatedMethod — asserting the deprecated alias forwards to upsertAll() */
        $result = (new RecordSet([$u]))->saveAll();

        $this->assertNotNull($result);
        $this->assertSame(1, $result->inserted);
        $this->assertNotNull($u->id);
        $this->assertNotNull(UserRecord::getOne($u->id));
    }

    public function testUpsertAllUpsertSkipsGeneratedColumns(): void
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
        $result = (new RecordSet([$loaded]))->upsertAll();
        $this->assertNotNull($result);
        $this->assertSame(1, $result->updated);

        $reloaded = DdlGeneratedColumnRecord::where('id', $id)->first();
        $this->assertNotNull($reloaded);
        $this->assertSame('after', $reloaded->value);
        $this->assertSame(7, $reloaded->scope_key, 'generated column still reflects its expression');
    }

    public function testUpsertAllUpsertClearsANullableColumnToNull(): void
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
        $result = (new RecordSet([$loaded]))->upsertAll();
        $this->assertNotNull($result);
        $this->assertSame(1, $result->updated);

        $reloaded = DdlGeneratedColumnRecord::where('id', $id)->first();
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->scope_id, 'a nullable column cleared to null must persist as null');
        $this->assertSame(0, $reloaded->scope_key, 'the generated column reflects the now-null source');
    }

    public function testUpsertAllUpsertDoesNotClobberFieldsAnotherRecordSent(): void
    {
        // The controller shape: two DB rows, then a heterogeneous payload built as PARTIAL keyed
        // records — each carrying a DIFFERENT subset of fields, no prior load. upsertAll() must update
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

        (new RecordSet([$pa, $pb]))->upsertAll();

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

    public function testUpsertAllChunkedInsertsUpdatesAndBackfillsAcrossChunks(): void
    {
        // Seed two rows to update later.
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

        // Chunked batch: 3 brand-new inserts + 2 partial keyed updates, chunkSize 2 → several chunks
        // (new-record chunks first, then keyed chunks). Exercises per-chunk commit, id back-fill per
        // chunk, and per-row dirty scoping across chunk boundaries.
        $new = [];
        foreach (['Carol', 'Dave', 'Erin'] as $name) {
            $u = new UserRecord();
            $u->name = $name;
            $new[] = $u;
        }
        $pa = new UserRecord();
        $pa->id = $a->id;
        $pa->name = 'Alice2';
        $pa->email = 'alice2@example.com';  // A changes name + email (not active)
        $pb = new UserRecord();
        $pb->id = $b->id;
        $pb->name = 'Bob2';                 // B changes name only

        $result = (new RecordSet([...$new, $pa, $pb]))->upsertAll(chunkSize: 2);

        $this->assertNotNull($result);
        $this->assertSame(3, $result->inserted);
        $this->assertSame(2, $result->updated);

        // Every new record got its generated id back and is clean.
        foreach ($new as $u) {
            $this->assertNotNull($u->id, 'new record gets its id back-filled per chunk');
            $this->assertFalse($u->isDirty());
        }

        // Keyed updates applied; columns a record never sent survive even across chunk boundaries.
        $ra = UserRecord::getOne((int) $a->id);
        $rb = UserRecord::getOne((int) $b->id);
        $this->assertNotNull($ra);
        $this->assertNotNull($rb);
        $this->assertSame('Alice2', $ra->name);
        $this->assertSame('alice2@example.com', $ra->email);
        $this->assertTrue($ra->active, 'A.active survives — never sent');
        $this->assertSame('Bob2', $rb->name);
        $this->assertSame('bob@example.com', $rb->email, 'B.email survives — never sent');
        $this->assertFalse($rb->active);

        $this->assertSame(5, UserRecord::countWhere('id > 0'));
    }

    public function testUpsertAllChunkedInsideOpenTransactionThrows(): void
    {
        // Per-chunk commit can't bound the footprint inside an outer transaction, so it's rejected
        // rather than silently degrading to atomic (which would defeat the reason chunkSize was passed).
        $u = new UserRecord();
        $u->name = 'Nested';

        $this->expectException(AttrecordException::class);
        UserRecord::transactional(static function () use ($u): void {
            (new RecordSet([$u]))->upsertAll(chunkSize: 1);
        });
    }

    public function testUpsertAllChunkedInsideTransactionWithFlagIsAtomic(): void
    {
        // With allowInTransactionChunking, the chunks run as separate statements inline in the outer
        // transaction — bounded statement size, still atomic. A committed outer txn persists all…
        $c1 = new UserRecord();
        $c1->name = 'C1';
        $c2 = new UserRecord();
        $c2->name = 'C2';
        UserRecord::transactional(static function () use ($c1, $c2): void {
            (new RecordSet([$c1, $c2]))->upsertAll(chunkSize: 1, allowInTransactionChunking: true);
        });
        $this->assertSame(2, UserRecord::countWhere('id > 0'), 'committed outer txn persists every chunk');

        // …and a rolled-back outer txn undoes every chunk (atomicity — not per-chunk-committed).
        try {
            UserRecord::transactional(static function (): void {
                $r1 = new UserRecord();
                $r1->name = 'R1';
                $r2 = new UserRecord();
                $r2->name = 'R2';
                (new RecordSet([$r1, $r2]))->upsertAll(chunkSize: 1, allowInTransactionChunking: true);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, UserRecord::countWhere('id > 0'), 'rolled-back outer txn persists nothing');
    }

    public function testUpsertAllMarksRecordsClean(): void
    {
        $u1 = new UserRecord();
        $u1->name = 'Alice';
        $u2 = new UserRecord();
        $u2->name = 'Bob';

        $set = new RecordSet([$u1, $u2]);
        $set->upsertAll();

        $this->assertFalse($u1->isDirty());
        $this->assertFalse($u2->isDirty());
    }

    public function testUpsertAllReturnsNullWhenAllClean(): void
    {
        $u = $this->makeUser('Alice');
        $set = new RecordSet([$u]);

        $this->assertNull($set->upsertAll());
    }

    public function testUpsertAllSkipsCleanRecords(): void
    {
        $u1 = $this->makeUser('Alice');
        $u2 = new UserRecord();
        $u2->name = 'Bob';

        // Only u2 is dirty; u1 is persisted and clean.
        $set = new RecordSet([$u1, $u2]);
        $set->upsertAll();

        // Total: Alice (from makeUser) + Bob (from upsertAll) = 2.
        $this->assertSame(2, UserRecord::countWhere('id > 0'));
    }

    public function testUpsertAllUpsertsKeyedRecords(): void
    {
        $alice = $this->makeUser('Alice');
        $bob = $this->makeUser('Bob');

        $alice->name = 'Alice Updated';
        $bob->name = 'Bob Updated';

        $set = new RecordSet([$alice, $bob]);
        $result = $set->upsertAll();

        $this->assertNotNull($result);
        $this->assertSame(0, $result->inserted); // rows already existed — INSERT IGNORE skipped them
        $this->assertSame(2, $result->updated);  // both updated by the CASE UPDATE

        $this->assertSame('Alice Updated', UserRecord::getOne((int) $alice->id)?->name);
        $this->assertSame('Bob Updated', UserRecord::getOne((int) $bob->id)?->name);
    }

    public function testUpsertAllEmptySetReturnsNull(): void
    {
        $set = new RecordSet([]);
        $this->assertNull($set->upsertAll());
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

        $users = UserRecord::find()->load('posts');

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

        $users = UserRecord::find()->load('posts');
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

        $posts = PostRecord::find()->load('user');

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
        $users = UserRecord::find()->load('posts.user');

        $first = $users->first();
        $this->assertNotNull($first);
        $this->assertInstanceOf(RecordSet::class, $first->posts);

        $post = $first->posts->first();
        $this->assertNotNull($post);
        $this->assertInstanceOf(UserRecord::class, $post->user);
    }

    // -----------------------------------------------------------------
    // loadMissing() + relation-load tracking
    // -----------------------------------------------------------------

    public function testRelationIsLoadedTracksLoadState(): void
    {
        $this->makeUser('Alice');

        $users = UserRecord::find();
        $alice = $users->first();
        $this->assertNotNull($alice);
        $this->assertFalse($alice->relationIsLoaded('posts'));

        $users->load('posts');
        $this->assertTrue($alice->relationIsLoaded('posts'));
    }

    public function testLoadMissingLoadsRecordsLackingTheRelation(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A1');
        $this->makePost((int) $alice->id, 'Post A2');

        $users = UserRecord::find(); // nothing loaded yet
        $users->loadMissing('posts');

        $loaded = $users->first();
        $this->assertNotNull($loaded);
        $this->assertInstanceOf(RecordSet::class, $loaded->posts);
        $this->assertCount(2, $loaded->posts);
    }

    public function testLoadMissingSkipsAnAlreadyLoadedRelation(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A1');
        $this->makePost((int) $alice->id, 'Post A2');

        $users = UserRecord::find()->load('posts');
        $loaded = $users->first();
        $this->assertNotNull($loaded);

        // Tamper with the loaded value: loadMissing must NOT overwrite it (it's already loaded).
        /** @var RecordSet<PostRecord> $empty */
        $empty = new RecordSet([]);
        $loaded->posts = $empty;
        $users->loadMissing('posts');

        $this->assertCount(0, $loaded->posts, 'loadMissing re-loaded a relation that was already loaded');
    }

    public function testChainedLoadMissing(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A');

        $users = UserRecord::find()->loadMissing('posts.user');

        $first = $users->first();
        $this->assertNotNull($first);
        $this->assertInstanceOf(RecordSet::class, $first->posts);
        $post = $first->posts->first();
        $this->assertNotNull($post);
        $this->assertInstanceOf(UserRecord::class, $post->user);
    }

    public function testDeprecatedWithAliasDelegatesToLoad(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A1');

        /** @psalm-suppress DeprecatedMethod intentionally exercising the back-compat alias */
        $users = UserRecord::find()->with('posts');

        $loaded = $users->first();
        $this->assertNotNull($loaded);
        $this->assertInstanceOf(RecordSet::class, $loaded->posts);
        $this->assertCount(1, $loaded->posts);
    }

    public function testLoadAcceptsMultiplePathsSharingAPrefix(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A1');
        $this->makePost((int) $alice->id, 'Post A2');

        // Two paths share the 'posts' prefix — it must load once, then descend to 'user'.
        $users = UserRecord::find()->load('posts.user', 'posts');

        $u = $users->first();
        $this->assertNotNull($u);
        $this->assertInstanceOf(RecordSet::class, $u->posts);
        $this->assertCount(2, $u->posts);

        $post = $u->posts->first();
        $this->assertNotNull($post);
        $this->assertInstanceOf(UserRecord::class, $post->user);
        $this->assertSame('Alice', $post->user->name);
    }

    public function testLoadMissingAcceptsMultiplePaths(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'Post A1');

        $users = UserRecord::find()->load('posts'); // 'posts' already loaded
        $loaded = $users->first();
        $this->assertNotNull($loaded);

        // Tamper 'posts' — loadMissing must keep it (loaded), but still resolve the new 'posts.user' leg.
        /** @var RecordSet<PostRecord> $empty */
        $empty = new RecordSet([]);
        $loaded->posts = $empty;

        $users->loadMissing('posts', 'posts.user');

        $this->assertCount(0, $loaded->posts, 'already-loaded prefix must be preserved');
    }

    public function testLoadMissingLoadsOnlyTheUnloadedPrefixThenDescends(): void
    {
        // Stand-ins: post→user→posts models order→customer→shipping.
        $alice = $this->makeUser('Alice');
        $bob = $this->makeUser('Bob');
        $this->makePost((int) $alice->id, 'A1');
        $this->makePost((int) $bob->id, 'B1');

        $posts = PostRecord::find(); // nothing loaded

        // Preload 'user' on ONE post only (the "already-loaded half").
        $preloaded = $posts->first();
        $this->assertNotNull($preloaded);
        (new RecordSet([$preloaded]))->load('user');
        $preloadedUser = $preloaded->user; // capture the instance to detect re-fetching

        // loadMissing must (a) load 'user' only on the not-yet-loaded post, leaving the preloaded
        // one's instance intact, then (b) descend to 'user.posts' for every post's user.
        $posts->loadMissing('user.posts');

        $this->assertSame($preloadedUser, $preloaded->user, 'already-loaded prefix must not be re-fetched');

        foreach ($posts as $post) {
            $this->assertInstanceOf(UserRecord::class, $post->user);
            $this->assertTrue($post->user->relationIsLoaded('posts'), 'descend level loaded for all');
            $this->assertInstanceOf(RecordSet::class, $post->user->posts);
        }
    }

    public function testSingleRecordLoad(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'A1');
        $this->makePost((int) $alice->id, 'A2');

        $user = UserRecord::find()->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->relationIsLoaded('posts'));

        $returned = $user->load('posts');

        $this->assertSame($user, $returned, 'load() is fluent on a single record');
        $this->assertInstanceOf(RecordSet::class, $user->posts);
        $this->assertCount(2, $user->posts);
    }

    public function testSingleRecordLoadMissingSkipsAlreadyLoaded(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'A1');

        $user = UserRecord::find()->first();
        $this->assertNotNull($user);
        $user->load('posts');

        /** @var RecordSet<PostRecord> $empty */
        $empty = new RecordSet([]);
        $user->posts = $empty;
        $user->loadMissing('posts');

        $this->assertCount(0, $user->posts, 'single-record loadMissing must preserve an already-loaded relation');
    }

    public function testLoadAndLoadMissingOnEmptySetAreNoops(): void
    {
        $empty = UserRecord::find('1 = 0'); // matches nothing
        $this->assertCount(0, $empty);

        // Must not query or error; fluent return.
        $this->assertSame($empty, $empty->load('posts'));
        $this->assertSame($empty, $empty->loadMissing('posts.user'));
    }

    public function testLoadDeduplicatesRepeatedPaths(): void
    {
        $alice = $this->makeUser('Alice');
        $this->makePost((int) $alice->id, 'A1');

        // 'posts' appears three times (twice bare, once as a prefix) — the trie collapses it.
        $users = UserRecord::find()->load('posts', 'posts', 'posts.user');

        $u = $users->first();
        $this->assertNotNull($u);
        $this->assertInstanceOf(RecordSet::class, $u->posts);
        $this->assertCount(1, $u->posts);
        $this->assertInstanceOf(UserRecord::class, $u->posts->first()?->user);
    }

    public function testDeleteAllOnEmptySetReturnsZero(): void
    {
        $empty = UserRecord::find('1 = 0');
        $this->assertSame(0, $empty->deleteAll());
    }

    // -----------------------------------------------------------------
    // ManyToMany + HasManyThrough
    // -----------------------------------------------------------------

    public function testManyToManyLoadsRelatedThroughPivot(): void
    {
        $user = $this->makeUser('U');
        $p1 = $this->makePost((int) $user->id, 'P1');
        $p2 = $this->makePost((int) $user->id, 'P2');
        $t1 = $this->makeTag('t1');
        $t2 = $this->makeTag('t2');
        $t3 = $this->makeTag('t3');
        $this->linkPostTag((int) $p1->id, (int) $t1->id);
        $this->linkPostTag((int) $p1->id, (int) $t2->id);
        $this->linkPostTag((int) $p2->id, (int) $t3->id);

        $byId = PostRecord::find()->load('manyTags')->recordsByKey('id');

        $p1Tags = $byId[(int) $p1->id]->manyTags;
        $p2Tags = $byId[(int) $p2->id]->manyTags;
        $this->assertInstanceOf(RecordSet::class, $p1Tags);
        $this->assertInstanceOf(RecordSet::class, $p2Tags);
        $this->assertCount(2, $p1Tags);
        $this->assertCount(1, $p2Tags);

        $names = $p1Tags->pluck('name');
        sort($names);
        $this->assertSame(['t1', 't2'], $names);
    }

    public function testManyToManyIsEmptyWhenNoPivotRows(): void
    {
        $user = $this->makeUser('U');
        $this->makePost((int) $user->id, 'Lonely');

        $post = PostRecord::find()->load('manyTags')->first();
        $this->assertNotNull($post);
        $this->assertInstanceOf(RecordSet::class, $post->manyTags);
        $this->assertCount(0, $post->manyTags);
    }

    public function testHasManyThroughLoadsFarRecords(): void
    {
        $u1 = $this->makeUser('U1');
        $u2 = $this->makeUser('U2');
        $p1 = $this->makePost((int) $u1->id, 'P1');
        $p2 = $this->makePost((int) $u1->id, 'P2');
        $p3 = $this->makePost((int) $u2->id, 'P3');
        $this->makeComment((int) $p1->id, 'c1');
        $this->makeComment((int) $p1->id, 'c2');
        $this->makeComment((int) $p2->id, 'c3');
        $this->makeComment((int) $p3->id, 'c4');

        $byName = UserRecord::find()->load('postComments')->recordsByKey('name');

        $u1Comments = $byName['U1']->postComments;
        $u2Comments = $byName['U2']->postComments;
        $this->assertInstanceOf(RecordSet::class, $u1Comments);
        $this->assertInstanceOf(RecordSet::class, $u2Comments);
        $this->assertCount(3, $u1Comments, 'comments across both of U1\'s posts');
        $this->assertCount(1, $u2Comments);
    }

    public function testHasManyThroughIsEmptyForRecordWithNoIntermediates(): void
    {
        $this->makeUser('Loner'); // no posts → no comments

        $user = UserRecord::find()->load('postComments')->first();
        $this->assertNotNull($user);
        $this->assertInstanceOf(RecordSet::class, $user->postComments);
        $this->assertCount(0, $user->postComments);
    }

    // -----------------------------------------------------------------
    // Record finder / update / delete edge branches
    // -----------------------------------------------------------------

    public function testGetOneOrFailThrowsWhenMissing(): void
    {
        $this->expectException(RecordNotFoundException::class);
        UserRecord::getOneOrFail(999999);
    }

    public function testDeleteWithoutPrimaryKeyThrows(): void
    {
        $this->expectException(RecordDeleteException::class);
        (new UserRecord())->delete();
    }

    public function testCountAndDeleteWhereAcceptAWhereClause(): void
    {
        $this->makeUser('Keep');
        $this->makeUser('Drop');

        $this->assertSame(1, UserRecord::countWhere(WhereClause::where('name', 'Drop')));
        $this->assertSame(1, UserRecord::deleteWhere(WhereClause::where('name', 'Drop')));
        $this->assertSame(0, UserRecord::countWhere(WhereClause::where('name', 'Drop')));
    }

    public function testWhereInWithCompositeColumnsDelegatesToTuples(): void
    {
        $u = $this->makeUser('A');

        $found = UserRecord::whereIn(['id', 'name'], [[(int) $u->id, 'A']]);

        $this->assertCount(1, $found);
    }

    public function testAggregateHelpersOverAScopedSet(): void
    {
        $a = $this->makeUser('agg-a');
        $b = $this->makeUser('agg-b');
        $c = $this->makeUser('agg-c');
        $ids = [(int) $a->id, (int) $b->id, (int) $c->id];
        $scope = WhereClause::whereIn('id', $ids);

        $this->assertSame(array_sum($ids), UserRecord::sumWhere('id', $scope));
        $this->assertEqualsWithDelta(array_sum($ids) / 3, UserRecord::avgWhere('id', $scope), 0.001);
        $this->assertEquals(min($ids), UserRecord::minWhere('id', $scope));
        $this->assertEquals(max($ids), UserRecord::maxWhere('id', $scope));
    }

    public function testAggregatesOnAnEmptyMatch(): void
    {
        $this->makeUser('agg-x');
        $none = WhereClause::where('name', 'does-not-exist');

        $this->assertSame(0, UserRecord::sumWhere('id', $none));
        $this->assertNull(UserRecord::avgWhere('id', $none));
        $this->assertNull(UserRecord::minWhere('id', $none));
    }

    public function testExistsWhere(): void
    {
        $this->makeUser('agg-present');

        $this->assertTrue(UserRecord::existsWhere('name = ?', ['agg-present']));
        $this->assertFalse(UserRecord::existsWhere('name = ?', ['agg-absent']));
    }

    public function testAggregateRejectsUnknownColumn(): void
    {
        $this->expectException(\Nandan108\Attrecord\Exception\SchemaException::class);
        UserRecord::sumWhere('not_a_column');
    }

    public function testUpsertAllWithForceWritesCleanRecords(): void
    {
        $u = $this->makeUser('Once');
        // Clean (just saved) — force makes upsertAll write it anyway.
        $result = (new RecordSet([$u]))->upsertAll(force: true);
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------
    // find-or-create
    // -----------------------------------------------------------------

    public function testFirstOrNewReturnsUnsavedNewAndNeverPersists(): void
    {
        $new = UserRecord::firstOrNew(['name' => 'Nemo'], ['email' => 'n@x.com']);
        $this->assertNull($new->id, 'firstOrNew does not save');
        $this->assertSame('Nemo', $new->name);
        $this->assertSame('n@x.com', $new->email);

        // A second call still finds nothing (the first never persisted).
        $this->assertNull(UserRecord::firstOrNew(['name' => 'Nemo'])->id);
    }

    public function testFindOrCreateCreatesThenReturnsExisting(): void
    {
        $a = UserRecord::findOrCreate(['name' => 'Zoe'], ['email' => 'zoe@x.com']);
        $this->assertNotNull($a->id);
        $this->assertSame('zoe@x.com', $a->email);

        // Second call matches the existing row; defaults are ignored.
        $b = UserRecord::findOrCreate(['name' => 'Zoe'], ['email' => 'other@x.com']);
        $this->assertSame($a->id, $b->id);
        $this->assertSame('zoe@x.com', $b->email);
    }

    public function testUpdateOrCreateUpdatesExisting(): void
    {
        $u = UserRecord::findOrCreate(['name' => 'Kai'], ['email' => 'kai@x.com']);

        $again = UserRecord::updateOrCreate(['name' => 'Kai'], ['email' => 'kai2@x.com']);

        $this->assertSame($u->id, $again->id);
        $this->assertSame('kai2@x.com', $again->email);
    }

    public function testUpdateOrCreateCreatesWhenMissing(): void
    {
        $created = UserRecord::updateOrCreate(['name' => 'Lena'], ['email' => 'lena@x.com']);

        $this->assertNotNull($created->id);
        $this->assertSame('lena@x.com', $created->email);
    }

    public function testMatchRequiresNonEmptyArray(): void
    {
        $this->expectException(AttrecordException::class);
        UserRecord::firstOrNew([]);
    }

    // -----------------------------------------------------------------
    // Lifecycle hooks + auto-timestamps
    // -----------------------------------------------------------------

    public function testAutoTimestampsSetOnInsert(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();

        $this->assertInstanceOf(\DateTimeImmutable::class, $rec->created_at);
        $this->assertInstanceOf(\DateTimeImmutable::class, $rec->updated_at);
        $this->assertEquals($rec->created_at, $rec->updated_at, 'both set to the same instant on insert');
    }

    public function testUpdatedAtBumpsOnChangeButCreatedAtDoesNot(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();
        $createdAtInsert = $rec->created_at;
        $updatedAtInsert = $rec->updated_at;

        $rec->name = 'b';
        $rec->save();

        $this->assertSame($createdAtInsert, $rec->created_at, 'created_at is never touched after insert');
        $this->assertGreaterThan($updatedAtInsert, $rec->updated_at, 'updated_at bumped on a real change');
    }

    public function testUpdatedAtNotBumpedOnCleanSave(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();
        $updatedAtInsert = $rec->updated_at;

        $rec->save(); // nothing changed → clean no-op

        $this->assertSame($updatedAtInsert, $rec->updated_at, 'a clean save must not bump updated_at');
    }

    public function testInsertLetsDbDefaultFireForNotNullColumnLeftNull(): void
    {
        // A NOT-NULL column left null on INSERT must be omitted so its DB default fires — emitting
        // an explicit NULL would violate the constraint (there is no legitimate "insert NULL into a
        // NOT-NULL column"). Before the fix, this save() threw. `note` is the control: it is
        // nullable-with-default, so its explicit null means "store NULL", not "use the default".
        $rec = new InsertDefaultRecord();
        $rec->note = null; // left null on purpose (nullable + default 'fallback')
        $rec->save();

        $this->assertNotNull($rec->id, 'insert succeeded and back-filled the auto-increment PK');

        $reloaded = InsertDefaultRecord::getOne($rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('pending', $reloaded->status, 'NOT-NULL literal default fired');
        $this->assertInstanceOf(
            \DateTimeImmutable::class,
            $reloaded->created_at,
            'NOT-NULL defaultExpr (CURRENT_TIMESTAMP) fired',
        );
        $this->assertNull(
            $reloaded->note,
            'nullable-with-default column stores the explicit NULL, not the default',
        );
    }

    public function testSaveIgnoreColumnsLetsNullableDefaultFireOnInsert(): void
    {
        // save(ignoreColumns:) drops a column from the INSERT so its DB default fires — even for a
        // *nullable* column, and even when the property holds a value (the ignore wins).
        $rec = new InsertDefaultRecord();
        $rec->status = 'shipped';               // non-ignored → still written verbatim
        $rec->note = 'should-be-dropped';       // ignored → the DB default takes its place
        $rec->save(ignoreColumns: ['note']);

        $this->assertNotNull($rec->id);
        $reloaded = InsertDefaultRecord::getOne($rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('shipped', $reloaded->status, 'non-ignored column is written normally');
        $this->assertSame('fallback', $reloaded->note, 'ignored nullable column took its DB default');
    }

    public function testSaveIgnoreColumnsLeavesColumnUntouchedOnUpdate(): void
    {
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';
        $rec->note = 'original';
        $rec->save();
        $this->assertNotNull($rec->id);
        $id = $rec->id;

        $rec->status = 'b';
        $rec->note = 'changed';                  // dirty, but ignored on this save
        $rec->save(ignoreColumns: ['note']);

        $reloaded = InsertDefaultRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('b', $reloaded->status, 'non-ignored dirty column is updated');
        $this->assertSame('original', $reloaded->note, 'ignored column is left untouched — keeps its stored value');
    }

    public function testSaveIgnoreColumnsRejectsUnknownColumn(): void
    {
        $this->expectException(\Nandan108\Attrecord\Exception\SchemaException::class);
        $rec = new InsertDefaultRecord();
        $rec->save(ignoreColumns: ['no_such_column']);
    }

    public function testInsertAllIgnoreColumnsLetsNullableDefaultFire(): void
    {
        $r1 = new InsertDefaultRecord();
        $r1->status = 'a';
        $r1->note = 'x';                         // ignored → default fires
        $r2 = new InsertDefaultRecord();
        $r2->status = 'b';
        $r2->note = 'y';                         // ignored → default fires

        (new RecordSet([$r1, $r2]))->insertAll(ignoreColumns: ['note']);

        $this->assertNotNull($r1->id);
        $reloaded = InsertDefaultRecord::getOne($r1->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('a', $reloaded->status, 'non-ignored column written verbatim');
        $this->assertSame('fallback', $reloaded->note, 'ignored nullable column took its DB default');
    }

    public function testUpsertAllIgnoreColumnsLetsNullableDefaultFireOnInsert(): void
    {
        // Plain-INSERT branch of upsertAll (PK-null record).
        $r = new InsertDefaultRecord();
        $r->status = 'a';
        $r->note = 'x';
        (new RecordSet([$r]))->upsertAll(ignoreColumns: ['note']);

        $this->assertNotNull($r->id);
        $reloaded = InsertDefaultRecord::getOne($r->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('fallback', $reloaded->note, 'ignored column took its DB default on the insert path');
    }

    public function testUpsertAllIgnoreColumnsLeavesColumnUntouchedOnKeyedUpdate(): void
    {
        // Keyed-upsert branch of upsertAll (PK-carrying record): an ignored dirty column is dropped
        // from both the INSERT membership and the UPDATE SET, so it keeps its stored value.
        $r = new InsertDefaultRecord();
        $r->status = 'a';
        $r->note = 'original';
        $r->save();
        $this->assertNotNull($r->id);
        $id = $r->id;

        $r->status = 'b';
        $r->note = 'changed';                    // dirty, but ignored on this upsert
        (new RecordSet([$r]))->upsertAll(ignoreColumns: ['note']);

        $reloaded = InsertDefaultRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('b', $reloaded->status, 'non-ignored dirty column updated');
        $this->assertSame('original', $reloaded->note, 'ignored column untouched on the keyed-upsert path');
    }

    public function testInsertAllIgnoreColumnsRejectsUnknownColumn(): void
    {
        $this->expectException(\Nandan108\Attrecord\Exception\SchemaException::class);
        $r = new InsertDefaultRecord();
        $r->status = 'a';
        (new RecordSet([$r]))->insertAll(ignoreColumns: ['no_such_column']);
    }

    public function testSaveAutoReadBackHealsIgnoredNullableDefault(): void
    {
        // readBack defaults to null (auto): ignoring the nullable-with-default `note` triggers a
        // read-back, so the in-memory value reflects the fired DB default and the record is clean.
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';
        $rec->note = 'dropped';
        $rec->save(ignoreColumns: ['note']);

        $this->assertSame('fallback', self::propValue($rec, 'note'), 'auto read-back healed the in-memory value from the DB default');
        $this->assertFalse($rec->isDirty(), 'record reads back clean after the auto read-back');
    }

    public function testSaveAutoReadBackHealsOmittedNotNullDefaultOnPlainSave(): void
    {
        // The v0.6.1 gap, now closed: a plain save() that omits a NOT-NULL default (created_at left
        // null → the insert rule drops it → DB fills now()) auto-heals the in-memory value, so the
        // record reflects the DB and reads back clean — no ignoreColumns needed.
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';                       // created_at left null → DB default fires
        $rec->save();

        $this->assertNotNull($rec->id);
        $this->assertInstanceOf(
            \DateTimeImmutable::class,
            self::propValue($rec, 'created_at'),
            'auto read-back healed the omitted NOT-NULL default on a plain save',
        );
        $this->assertFalse($rec->isDirty());
    }

    public function testSaveAutoReadBackIsNoOpWhenNothingDiverged(): void
    {
        // Every DB-populated column is provided, and there is no generated column → auto has nothing
        // to read back → the record is already clean and untouched (no needless work on the hot path).
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';
        $rec->note = 'set';
        $rec->created_at = new \DateTimeImmutable('2021-01-01T00:00:00Z');
        $rec->save();

        $this->assertNotNull($rec->id);
        $this->assertSame('set', self::propValue($rec, 'note'), 'in-memory value untouched (no read-back needed)');
        $this->assertFalse($rec->isDirty());
    }

    public function testSaveReadBackFalseSkipsHealing(): void
    {
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';
        $rec->save(ignoreColumns: ['note'], readBack: false);

        $this->assertNull($rec->note, 'readBack:false leaves the in-memory value untouched');
        $this->assertNotNull($rec->id);
        $reloaded = InsertDefaultRecord::getOne($rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('fallback', $reloaded->note, 'the DB default still fired');
    }

    public function testSaveReadBackTrueRefreshesOmittedNotNullDefault(): void
    {
        // Explicit readBack:true refreshes even without an ignored column — the NOT-NULL `created_at`
        // default (omitted by the insert rule) is read back onto the record.
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';                       // created_at left null → omitted → DB default fires
        $rec->save(readBack: true);

        $this->assertInstanceOf(
            \DateTimeImmutable::class,
            $rec->created_at,
            'readBack:true hydrated the omitted NOT-NULL default in memory',
        );
        $this->assertFalse($rec->isDirty());
    }

    public function testInsertAllAutoReadBackHealsIgnoredNullableDefault(): void
    {
        $r1 = new InsertDefaultRecord();
        $r1->status = 'a';
        $r1->note = 'x';
        $r2 = new InsertDefaultRecord();
        $r2->status = 'b';
        $r2->note = 'y';

        (new RecordSet([$r1, $r2]))->insertAll(ignoreColumns: ['note']);

        $this->assertSame('fallback', self::propValue($r1, 'note'), 'auto read-back healed r1 in memory');
        $this->assertSame('fallback', self::propValue($r2, 'note'), 'auto read-back healed r2 in memory');
        $this->assertFalse($r1->isDirty());
        $this->assertFalse($r2->isDirty());
    }

    public function testUpsertAllAutoReadBackHealsIgnoredNullableDefault(): void
    {
        $r = new InsertDefaultRecord();
        $r->status = 'a';
        $r->note = 'x';
        (new RecordSet([$r]))->upsertAll(ignoreColumns: ['note']);

        $this->assertSame('fallback', self::propValue($r, 'note'), 'auto read-back healed the in-memory value on the bulk path');
        $this->assertFalse($r->isDirty());
    }

    public function testSaveReadBackExplicitListReadsOnlyNamedColumns(): void
    {
        // Both note (ignored nullable-default) and created_at (NOT-NULL default omitted by the insert
        // rule) diverge from memory; read back only note. note is healed from its DB default;
        // created_at is NOT in the list, so it stays null in memory even though the DB stored now().
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';                       // written, so the INSERT has a column
        $rec->note = 'x';
        $rec->save(ignoreColumns: ['note'], readBack: ['note']);

        $this->assertSame('fallback', self::propValue($rec, 'note'), 'listed column read back from the DB default');
        $this->assertNull(self::propValue($rec, 'created_at'), 'unlisted omitted column is not read back (stays null in memory)');

        $this->assertNotNull($rec->id);
        $reloaded = InsertDefaultRecord::getOne($rec->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(\DateTimeImmutable::class, $reloaded->created_at, 'the DB did store the created_at default');
    }

    public function testSaveReadBackUnknownColumnThrows(): void
    {
        $this->expectException(\Nandan108\Attrecord\Exception\SchemaException::class);
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';
        $rec->save(readBack: ['no_such_col']);
    }

    public function testInsertAllReadBackExplicitList(): void
    {
        $r = new InsertDefaultRecord();
        $r->status = 'a';
        $r->note = 'x';
        (new RecordSet([$r]))->insertAll(ignoreColumns: ['note'], readBack: ['note']);

        $this->assertSame('fallback', self::propValue($r, 'note'), 'explicit-list read-back healed the bulk-inserted row');
    }

    public function testAutoReadBackRefreshesGeneratedColumnOnInsert(): void
    {
        // scope_key is STORED as COALESCE(scope_id, 0); the dependency scan links it to scope_id, so
        // an insert that writes scope_id auto-reads-back the recomputed generated value.
        $rec = new DdlGeneratedColumnRecord();
        $rec->scope_id = 7;
        $rec->value = 'x';
        $rec->save();

        $this->assertSame(7, self::propValue($rec, 'scope_key'), 'generated column healed in memory on insert');
        $this->assertFalse($rec->isDirty());
    }

    public function testAutoReadBackRefreshesGeneratedColumnWhenSourceUpdated(): void
    {
        $rec = new DdlGeneratedColumnRecord();
        $rec->scope_id = 1;
        $rec->value = 'x';
        $rec->save();
        $this->assertSame(1, self::propValue($rec, 'scope_key'));

        // Updating scope_id (a dependency of scope_key) recomputes the generated column: auto reads
        // it back so the in-memory value tracks the DB.
        $rec->scope_id = 5;
        $rec->save();

        $this->assertSame(5, self::propValue($rec, 'scope_key'), 'generated column refreshed after its source changed');
        $this->assertFalse($rec->isDirty());
    }

    public function testUpsertAllSetsTimestamps(): void
    {
        $set = new RecordSet([
            (function (): TimestampedRecord {
                $r = new TimestampedRecord();
                $r->name = 'a';

                return $r;
            })(),
            (function (): TimestampedRecord {
                $r = new TimestampedRecord();
                $r->name = 'b';

                return $r;
            })(),
        ]);
        $set->upsertAll();

        foreach ($set as $r) {
            $this->assertInstanceOf(\DateTimeImmutable::class, $r->created_at);
            $this->assertInstanceOf(\DateTimeImmutable::class, $r->updated_at);
        }
    }

    public function testAfterSaveFiresWithInsertThenUpdateFlag(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();
        $this->assertContains('afterSave', $rec->hookLog);
        $this->assertTrue($rec->lastAfterSaveWasInsert, 'afterSave(wasInsert=true) on INSERT');

        $rec->name = 'b';
        $rec->save();
        $this->assertFalse($rec->lastAfterSaveWasInsert, 'afterSave(wasInsert=false) on UPDATE');
    }

    public function testAfterSaveDoesNotFireOnCleanSave(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();
        $rec->hookLog = [];

        $rec->save(); // no-op

        $this->assertNotContains('afterSave', $rec->hookLog);
    }

    public function testBeforeAndAfterDeleteFire(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();

        $rec->delete();

        $log = $rec->hookLog;
        $this->assertContains('beforeDelete', $log);
        $this->assertContains('afterDelete', $log);
        $this->assertTrue(
            array_search('beforeDelete', $log, true) < array_search('afterDelete', $log, true),
            'beforeDelete fires before afterDelete',
        );
    }

    public function testAfterLoadFiresOnHydration(): void
    {
        $seed = new TimestampedRecord();
        $seed->name = 'a';
        $seed->save();

        $loaded = TimestampedRecord::find()->first();
        $this->assertNotNull($loaded);
        $this->assertContains('afterLoad', $loaded->hookLog);
    }

    public function testUpsertAllFiresAfterSavePerRecord(): void
    {
        $a = new TimestampedRecord();
        $a->name = 'a';
        $b = new TimestampedRecord();
        $b->name = 'b';
        (new RecordSet([$a, $b]))->upsertAll();

        $this->assertTrue($a->lastAfterSaveWasInsert);
        $this->assertTrue($b->lastAfterSaveWasInsert);
        $this->assertContains('afterSave', $a->hookLog);
        $this->assertContains('afterSave', $b->hookLog);
    }

    /** Push updated_at far into the past so a subsequent auto-bump to `now` is unmistakable. */
    private function ageUpdatedAt(int $id): \DateTimeImmutable
    {
        $old = new \DateTimeImmutable('2000-01-01 00:00:00');
        TimestampedRecord::updateWhere(['updated_at' => $old], 'id = ?', [$id]);

        return $old;
    }

    public function testUpdateWhereBumpsUpdatedAt(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();
        $old = $this->ageUpdatedAt((int) $rec->id);

        TimestampedRecord::updateWhere(['name' => 'b'], 'id = ?', [(int) $rec->id]);

        $reloaded = TimestampedRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('b', $reloaded->name);
        $this->assertNotNull($reloaded->updated_at);
        $this->assertGreaterThan($old, $reloaded->updated_at, 'updateWhere bumped updated_at to now');
    }

    public function testUpdateWhereRespectsAnExplicitUpdatedAt(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();

        $fixed = new \DateTimeImmutable('2000-01-01 00:00:00');
        TimestampedRecord::updateWhere(['name' => 'b', 'updated_at' => $fixed], 'id = ?', [(int) $rec->id]);

        $reloaded = TimestampedRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->updated_at);
        $this->assertSame('2000-01-01', $reloaded->updated_at->format('Y-m-d'));
    }

    public function testUpdateByWhereBumpsUpdatedAt(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();

        $old = $this->ageUpdatedAt((int) $rec->id);

        $rec->name = 'b';
        $rec->updateByWhere('id = ?', [(int) $rec->id]);

        $reloaded = TimestampedRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('b', $reloaded->name);
        $this->assertNotNull($reloaded->updated_at);
        $this->assertGreaterThan($old, $reloaded->updated_at, 'updateByWhere stamped updated_at now');
    }

    public function testUpdateByUniqueKeyBumpsUpdatedAt(): void
    {
        $rec = new TimestampedRecord();
        $rec->name = 'a';
        $rec->save();
        $old = $this->ageUpdatedAt((int) $rec->id);

        $rec->name = 'b';
        $rec->updateByUniqueKey(['name']);

        $reloaded = TimestampedRecord::getOne((int) $rec->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('b', $reloaded->name);
        $this->assertNotNull($reloaded->updated_at);
        $this->assertGreaterThan($old, $reloaded->updated_at, 'updateByUniqueKey stamped updated_at now');
    }

    // -----------------------------------------------------------------
    // ArrayAccess / Iterator / Countable
    // -----------------------------------------------------------------

    public function testArrayAccessMutation(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');

        $set = new RecordSet([$a]);
        $set[] = $b; // offsetSet with null → append

        $this->assertTrue(isset($set[0]));
        $this->assertCount(2, $set);
        $this->assertSame($b, $set[1]); // offsetGet

        unset($set[1]); // offsetUnset
        $this->assertCount(1, $set);
    }

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
