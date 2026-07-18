<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\PostRecord;
use Nandan108\Attrecord\Tests\Fixtures\TagRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;

/**
 * Shared polymorphic-relation cases (MorphMany / MorphOne / MorphTo + dot-notation chains),
 * run against both MySQL and PostgreSQL.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait PolymorphicRelationCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [UserRecord::class, PostRecord::class, TagRecord::class];
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

    private function tagUser(UserRecord $user, string $tagName): TagRecord
    {
        $t = new TagRecord();
        $t->tagable_type = 'user';
        $t->tagable_id = (int) $user->id;
        $t->name = $tagName;
        $t->save();

        return $t;
    }

    private function tagPost(PostRecord $post, string $tagName): TagRecord
    {
        $t = new TagRecord();
        $t->tagable_type = 'post';
        $t->tagable_id = (int) $post->id;
        $t->name = $tagName;
        $t->save();

        return $t;
    }

    // -----------------------------------------------------------------
    // MorphMany
    // -----------------------------------------------------------------

    public function testMorphManyLoadsTags(): void
    {
        $alice = $this->makeUser('Alice');
        $bob = $this->makeUser('Bob');

        $this->tagUser($alice, 'vip');
        $this->tagUser($alice, 'priority');
        $this->tagUser($bob, 'standard');

        $users = UserRecord::find()->load('tags');

        /** @var array<string, UserRecord> $byName */
        $byName = $users->recordsByKey('name');

        $this->assertInstanceOf(RecordSet::class, $byName['Alice']->tags);
        $this->assertInstanceOf(RecordSet::class, $byName['Bob']->tags);
        $this->assertCount(2, $byName['Alice']->tags);
        $this->assertCount(1, $byName['Bob']->tags);
    }

    public function testLoadMissingOverMorphRelation(): void
    {
        $alice = $this->makeUser('Alice');
        $bob = $this->makeUser('Bob');
        $this->tagUser($alice, 'vip');
        $this->tagUser($bob, 'standard');

        $users = UserRecord::find();

        // Preload the morph relation on one user only.
        $first = $users->first();
        $this->assertNotNull($first);
        (new RecordSet([$first]))->load('tags');
        $firstTags = $first->tags;

        $users->loadMissing('tags');

        // Already-loaded morph relation preserved; the other user's is now loaded too.
        $this->assertSame($firstTags, $first->tags);
        foreach ($users as $user) {
            $this->assertInstanceOf(RecordSet::class, $user->tags);
        }
    }

    public function testMorphManyDoesNotLeakAcrossTypes(): void
    {
        $alice = $this->makeUser('Alice');
        $post = $this->makePost((int) $alice->id, 'Hello');

        $this->tagUser($alice, 'user-tag');
        $this->tagPost($post, 'post-tag');

        $users = UserRecord::find()->load('tags');
        $alice = $users->first();
        $this->assertNotNull($alice);

        // Alice's tags must not include the post tag.
        $this->assertInstanceOf(RecordSet::class, $alice->tags);
        $this->assertCount(1, $alice->tags);
        $this->assertSame('user-tag', $alice->tags->first()?->name);
    }

    public function testMorphManyEmptyRelationIsEmptyRecordSet(): void
    {
        $this->makeUser('Alice'); // no tags

        $users = UserRecord::find()->load('tags');
        $alice = $users->first();
        $this->assertNotNull($alice);
        $this->assertInstanceOf(RecordSet::class, $alice->tags);
        $this->assertCount(0, $alice->tags);
    }

    // -----------------------------------------------------------------
    // MorphOne
    // -----------------------------------------------------------------

    public function testMorphOneLoadsFirstTag(): void
    {
        $alice = $this->makeUser('Alice');
        $this->tagUser($alice, 'alpha');
        $this->tagUser($alice, 'beta');

        $users = UserRecord::find()->load('firstTag');
        $alice = $users->first();
        $this->assertNotNull($alice);
        $this->assertInstanceOf(TagRecord::class, $alice->firstTag);
        // Only one record assigned (the first match).
        $this->assertSame('alpha', $alice->firstTag->name);
    }

    public function testMorphOneIsNullWhenNoTags(): void
    {
        $this->makeUser('Alice'); // no tags

        $users = UserRecord::find()->load('firstTag');
        $alice = $users->first();
        $this->assertNotNull($alice);
        $this->assertNull($alice->firstTag);
    }

    // -----------------------------------------------------------------
    // MorphTo
    // -----------------------------------------------------------------

    public function testMorphToLoadsUserParent(): void
    {
        $alice = $this->makeUser('Alice');
        $this->tagUser($alice, 'vip');

        $tags = TagRecord::find()->load('tagable');
        $tag = $tags->first();

        $this->assertNotNull($tag);
        $this->assertInstanceOf(UserRecord::class, $tag->tagable);
        $this->assertSame('Alice', $tag->tagable->name);
    }

    public function testMorphToLoadsPostParent(): void
    {
        $alice = $this->makeUser('Alice');
        $post = $this->makePost((int) $alice->id, 'Hello World');
        $this->tagPost($post, 'featured');

        $tags = TagRecord::find()->load('tagable');
        $tag = $tags->first();

        $this->assertNotNull($tag);
        $this->assertInstanceOf(PostRecord::class, $tag->tagable);
        $this->assertSame('Hello World', $tag->tagable->title);
    }

    public function testMorphToMixedParentTypes(): void
    {
        $alice = $this->makeUser('Alice');
        $post = $this->makePost((int) $alice->id, 'Hello');

        $userTag = $this->tagUser($alice, 'vip');
        $postTag = $this->tagPost($post, 'featured');

        $tags = TagRecord::find()->load('tagable');

        /** @var array<int, TagRecord> $byId */
        $byId = $tags->recordsByKey('id');

        $this->assertInstanceOf(UserRecord::class, $byId[(int) $userTag->id]->tagable);
        $this->assertInstanceOf(PostRecord::class, $byId[(int) $postTag->id]->tagable);
    }

    public function testMorphToUnknownTypeStaysNull(): void
    {
        // Insert a tag with an unregistered type directly (unquoted identifiers parse on both
        // MySQL and PostgreSQL).
        static::$pdo->exec(
            "INSERT INTO attrecord_tags (tagable_type, tagable_id, name) VALUES ('unknown', 1, 'orphan')",
        );

        $tags = TagRecord::find()->load('tagable');
        $tag = $tags->first();

        $this->assertNotNull($tag);
        $this->assertNull($tag->tagable);
    }

    // -----------------------------------------------------------------
    // Dot-notation chain: tags.tagable
    // -----------------------------------------------------------------

    public function testMorphToChainedFromParent(): void
    {
        $alice = $this->makeUser('Alice');
        $this->tagUser($alice, 'vip');

        // Load users → tags → tagable (roundtrip back to User).
        $users = UserRecord::find()->load('tags.tagable');

        $user = $users->first();
        $this->assertNotNull($user);
        $this->assertInstanceOf(RecordSet::class, $user->tags);

        $tagRecord = $user->tags->first();
        $this->assertNotNull($tagRecord);
        $this->assertInstanceOf(TagRecord::class, $tagRecord);
        $this->assertInstanceOf(UserRecord::class, $tagRecord->tagable);
        $this->assertSame('Alice', $tagRecord->tagable->name);
    }
}
