<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Exception\OptimisticLockException;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\VersionedRecord;

/**
 * Shared cases for the {@see \Nandan108\Attrecord\Attribute\Version} contract: optimistic locking.
 *
 * The version is seeded on INSERT and incremented on every UPDATE, which is guarded by the value the
 * record was loaded with. A write whose guard no longer matches — because another writer moved the
 * row on — throws {@see OptimisticLockException} instead of silently clobbering that change.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait OptimisticLockCases
{
    /** @return list<class-string<\Nandan108\Attrecord\Record>> */
    protected static function recordClasses(): array
    {
        return [VersionedRecord::class];
    }

    /**
     * Read a column dynamically so psalm cannot narrow it to the literal last assigned — a read-back
     * may have replaced it from the database, which psalm does not model.
     */
    private static function col(object $record, string $name): mixed
    {
        return $record->{$name};
    }

    private function seed(string $name): VersionedRecord
    {
        $r = new VersionedRecord();
        $r->name = $name;
        $r->save();

        return $r;
    }

    public function testInsertSeedsVersion(): void
    {
        $r = $this->seed('first');

        $this->assertSame(1, $r->version, 'a new record is seeded to version 1 in memory');
        $this->assertNotNull($r->id);

        $reloaded = VersionedRecord::getOne($r->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(1, $reloaded->version, 'and the seeded value is what was stored');
    }

    public function testUpdateBumpsVersion(): void
    {
        $r = $this->seed('before');
        $id = (int) $r->id;

        $r->name = 'after';
        $r->save();

        $this->assertSame(2, $r->version, 'the in-memory version tracks the write');

        $reloaded = VersionedRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame(2, $reloaded->version);
        $this->assertSame('after', $reloaded->name);
    }

    public function testCleanSaveDoesNotBumpVersion(): void
    {
        $r = $this->seed('unchanged');

        $r->save(); // nothing dirty → no UPDATE is issued at all

        $this->assertSame(1, $r->version, 'a no-op save must not consume a version');
    }

    public function testConcurrentUpdateThrowsOptimisticLockException(): void
    {
        $r = $this->seed('original');
        $id = (int) $r->id;

        // Two independent instances of the same row — the exact situation the version guards.
        $a = VersionedRecord::getOne($id);
        $b = VersionedRecord::getOne($id);
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $a->name = 'written by A';
        $a->save();
        $this->assertSame(2, $a->version);

        // B still holds version 1, so its guard no longer matches.
        $b->name = 'written by B';
        try {
            $b->save();
            $this->fail('expected OptimisticLockException');
        } catch (OptimisticLockException $e) {
            $this->assertSame(VersionedRecord::class, $e->recordClass);
            $this->assertSame(1, $e->expectedVersion);
        }

        // A's write survived — B did not clobber it.
        $reloaded = VersionedRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('written by A', $reloaded->name);
        $this->assertSame(2, $reloaded->version);
    }

    public function testReloadAfterConflictLetsTheWriteSucceed(): void
    {
        $r = $this->seed('original');
        $id = (int) $r->id;

        $stale = VersionedRecord::getOne($id);
        $this->assertNotNull($stale);

        $other = VersionedRecord::getOne($id);
        $this->assertNotNull($other);
        $other->name = 'moved on';
        $other->save();

        $stale->name = 'retry';
        try {
            $stale->save();
            $this->fail('expected OptimisticLockException');
        } catch (OptimisticLockException) {
            // The documented recovery: reload and reapply.
        }

        $fresh = VersionedRecord::getOne($id);
        $this->assertNotNull($fresh);
        $fresh->name = 'retry';
        $fresh->save();

        $this->assertSame(3, $fresh->version, 'seed=1, other=2, retry=3');

        $reloaded = VersionedRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('retry', $reloaded->name);
    }

    public function testConflictIsDetectedOnTheReturningPathToo(): void
    {
        // With a read-back requested, the UPDATE goes through `… RETURNING` on PostgreSQL/SQLite
        // instead of plain exec(). A failed version guard yields *no returned row* there, rather than
        // a zero affected-row count — both must surface as the same conflict.
        $r = $this->seed('original');
        $id = (int) $r->id;

        $winner = VersionedRecord::getOne($id);
        $loser = VersionedRecord::getOne($id);
        $this->assertNotNull($winner);
        $this->assertNotNull($loser);

        $winner->name = 'winner';
        $winner->save();

        $loser->name = 'loser';
        $this->expectException(OptimisticLockException::class);
        $loser->save(readBack: true);
    }

    public function testSetBasedUpdateBumpsTheVersion(): void
    {
        // A set-based UPDATE cannot guard (it matches by predicate, not from loaded state), but it
        // MUST bump — otherwise a stale holder's guarded write would still match and clobber it.
        $r = $this->seed('before');
        $id = (int) $r->id;

        $holder = VersionedRecord::getOne($id);
        $this->assertNotNull($holder);

        $affected = VersionedRecord::updateWhere(['name' => 'set-based'], 'id = ?', [$id]);
        $this->assertSame(1, $affected);

        $reloaded = VersionedRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('set-based', $reloaded->name);
        $this->assertSame(2, $reloaded->version, 'the set-based update consumed a version');

        // And the holder, still on version 1, is now correctly locked out.
        $holder->name = 'stale write';
        $this->expectException(OptimisticLockException::class);
        $holder->save();
    }

    public function testBulkInsertSeedsVersion(): void
    {
        $a = new VersionedRecord();
        $a->name = 'bulk-a';
        $b = new VersionedRecord();
        $b->name = 'bulk-b';

        (new RecordSet([$a, $b]))->insertAll();

        $this->assertSame(1, $a->version, 'insertAll seeds the version in memory');
        $this->assertSame(1, $b->version);

        $this->assertNotNull($a->id);
        $reloaded = VersionedRecord::getOne($a->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(1, $reloaded->version, 'and the seeded value is what was stored');
    }

    public function testBulkUpsertSeedsVersionForNewRecords(): void
    {
        $a = new VersionedRecord();
        $a->name = 'upsert-new';

        (new RecordSet([$a]))->upsertAll();

        $this->assertSame(1, $a->version);
        $this->assertNotNull($a->id);
        $reloaded = VersionedRecord::getOne($a->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(1, $reloaded->version);
    }

    public function testFailedGuardLeavesTheRecordRecoverable(): void
    {
        // After a conflict the caller must be able to diagnose and retry: the in-memory version must
        // NOT have been bumped (it still reflects what we guarded on) and the pending change must
        // still be pending, or "reload and reapply" would have nothing to reapply.
        $r = $this->seed('original');
        $id = (int) $r->id;

        $loser = VersionedRecord::getOne($id);
        $winner = VersionedRecord::getOne($id);
        $this->assertNotNull($loser);
        $this->assertNotNull($winner);

        $winner->name = 'winner';
        $winner->save();

        $loser->name = 'loser';
        try {
            $loser->save();
            $this->fail('expected OptimisticLockException');
        } catch (OptimisticLockException) {
        }

        $this->assertSame(1, $loser->version, 'the version must not be bumped by a failed write');
        $this->assertTrue($loser->isDirty(), 'the unsaved change is still pending, so it can be reapplied');
        $this->assertSame('loser', self::col($loser, 'name'));
    }

    public function testSuccessfulUpdateWithReadBackKeepsTheBumpedVersion(): void
    {
        // Happy path of the version + read-back combination: on PG/SQLite this update runs through
        // `… RETURNING`, and the read-back must not clobber the freshly bumped in-memory version.
        $r = $this->seed('before');
        $id = (int) $r->id;

        $r->name = 'after';
        $r->save(readBack: true);

        $this->assertSame(2, $r->version, 'read-back left the bumped version intact');
        $this->assertSame('after', self::col($r, 'name'), 'and returned the written value from the DB');
        $this->assertFalse($r->isDirty());

        $reloaded = VersionedRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame(2, $reloaded->version);
    }

    public function testTargetedReadBackListCombinesWithTheGuard(): void
    {
        $r = $this->seed('before');

        $r->name = 'after';
        $r->save(readBack: ['name']);

        $this->assertSame(2, $r->version);
        $this->assertSame('after', self::col($r, 'name'));
    }

    public function testIgnoringTheVersionColumnDoesNotDisableTheGuard(): void
    {
        // The guard is not opt-out-able via ignoreColumns: the version is managed by attrecord, not
        // written as a caller column, so dropping it from the write changes nothing.
        $r = $this->seed('original');
        $id = (int) $r->id;

        $loser = VersionedRecord::getOne($id);
        $winner = VersionedRecord::getOne($id);
        $this->assertNotNull($loser);
        $this->assertNotNull($winner);

        $winner->name = 'winner';
        $winner->save();

        $loser->name = 'loser';
        $this->expectException(OptimisticLockException::class);
        $loser->save(ignoreColumns: ['version']);
    }

    public function testForcedSaveStillGuardsAndBumps(): void
    {
        // force: true writes clean columns too — so unlike a plain no-op save it *does* issue an
        // UPDATE, and must therefore consume a version.
        $r = $this->seed('unchanged');

        $r->save(force: true);

        $this->assertSame(2, $r->version, 'a forced write is a real write and consumes a version');
    }

    public function testTamperingWithTheVersionIsTreatedAsAConflict(): void
    {
        // The guard uses whatever the property holds, so hand-editing it cannot be used to bypass
        // the check — it just guards on a value the row does not have.
        $r = $this->seed('original');

        $r->name = 'changed';
        $r->version = 99;

        $this->expectException(OptimisticLockException::class);
        $r->save();
    }

    public function testSetBasedUpdateWithAnExplicitVersionSkipsTheAutoBump(): void
    {
        $r = $this->seed('original');
        $id = (int) $r->id;

        // The caller set the column explicitly, so attrecord must not also bump it.
        $affected = VersionedRecord::updateWhere(['name' => 'set', 'version' => 42], 'id = ?', [$id]);
        $this->assertSame(1, $affected);

        $reloaded = VersionedRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame(42, $reloaded->version, 'the explicit value wins over the auto-bump');
    }

    public function testBulkKeyedUpsertNeitherGuardsNorBumps(): void
    {
        // Characterisation of the known 0.8.0 gap, pinned so a future change is deliberate: the keyed
        // bulk upsert has no per-row version predicate, so a stale holder's write lands where save()
        // would have thrown. It does not *regress* the version either — the version is clean on the
        // holder, so it never enters the CASE-UPDATE's SET list.
        $r = $this->seed('original');
        $id = (int) $r->id;

        $stale = VersionedRecord::getOne($id);   // holds version 1
        $mover = VersionedRecord::getOne($id);
        $this->assertNotNull($stale);
        $this->assertNotNull($mover);

        $mover->name = 'moved on';
        $mover->save();
        $this->assertSame(2, $mover->version);

        // save() would throw here; upsertAll() does not.
        $stale->name = 'stale bulk write';
        (new RecordSet([$stale]))->upsertAll();

        $reloaded = VersionedRecord::getOne($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('stale bulk write', $reloaded->name, 'the bulk path is unguarded — the stale write lands');
        $this->assertSame(2, $reloaded->version, 'and it neither bumps nor regresses the stored version');
    }

    public function testDeletedRowAlsoFailsTheGuard(): void
    {
        $r = $this->seed('doomed');
        $id = (int) $r->id;

        $holder = VersionedRecord::getOne($id);
        $this->assertNotNull($holder);

        $r->delete();

        $holder->name = 'write into the void';
        $this->expectException(OptimisticLockException::class);
        $holder->save();
    }
}
