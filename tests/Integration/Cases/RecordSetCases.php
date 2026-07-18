<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\CommentRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlGeneratedColumnRecord;
use Nandan108\Attrecord\Tests\Fixtures\PostRecord;
use Nandan108\Attrecord\Tests\Fixtures\PostTagPivot;
use Nandan108\Attrecord\Tests\Fixtures\TagRecord;
use Nandan108\Attrecord\Tests\Fixtures\TimestampedRecord;
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
        return [
            UserRecord::class,
            PostRecord::class,
            DdlGeneratedColumnRecord::class,
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

    public function testSaveAllChunkedInsertsUpdatesAndBackfillsAcrossChunks(): void
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

        $result = (new RecordSet([...$new, $pa, $pb]))->saveAll(chunkSize: 2);

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

    public function testSaveAllChunkedInsideOpenTransactionThrows(): void
    {
        // Per-chunk commit can't bound the footprint inside an outer transaction, so it's rejected
        // rather than silently degrading to atomic (which would defeat the reason chunkSize was passed).
        $u = new UserRecord();
        $u->name = 'Nested';

        $this->expectException(AttrecordException::class);
        UserRecord::transactional(static function () use ($u): void {
            (new RecordSet([$u]))->saveAll(chunkSize: 1);
        });
    }

    public function testSaveAllChunkedInsideTransactionWithFlagIsAtomic(): void
    {
        // With allowInTransactionChunking, the chunks run as separate statements inline in the outer
        // transaction — bounded statement size, still atomic. A committed outer txn persists all…
        $c1 = new UserRecord();
        $c1->name = 'C1';
        $c2 = new UserRecord();
        $c2->name = 'C2';
        UserRecord::transactional(static function () use ($c1, $c2): void {
            (new RecordSet([$c1, $c2]))->saveAll(chunkSize: 1, allowInTransactionChunking: true);
        });
        $this->assertSame(2, UserRecord::countWhere('id > 0'), 'committed outer txn persists every chunk');

        // …and a rolled-back outer txn undoes every chunk (atomicity — not per-chunk-committed).
        try {
            UserRecord::transactional(static function (): void {
                $r1 = new UserRecord();
                $r1->name = 'R1';
                $r2 = new UserRecord();
                $r2->name = 'R2';
                (new RecordSet([$r1, $r2]))->saveAll(chunkSize: 1, allowInTransactionChunking: true);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, UserRecord::countWhere('id > 0'), 'rolled-back outer txn persists nothing');
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

    public function testSaveAllSetsTimestamps(): void
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
        $set->saveAll();

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

    public function testSaveAllFiresAfterSavePerRecord(): void
    {
        $a = new TimestampedRecord();
        $a->name = 'a';
        $b = new TimestampedRecord();
        $b->name = 'b';
        (new RecordSet([$a, $b]))->saveAll();

        $this->assertTrue($a->lastAfterSaveWasInsert);
        $this->assertTrue($b->lastAfterSaveWasInsert);
        $this->assertContains('afterSave', $a->hookLog);
        $this->assertContains('afterSave', $b->hookLog);
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
