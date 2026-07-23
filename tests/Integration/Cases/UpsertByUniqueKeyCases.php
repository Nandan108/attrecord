<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\UpsertByUniqueKeyRecord;

/**
 * Shared burn-free upsert-by-unique-key cases:
 *  - Record::upsertByUniqueKey(..., preserveAutoIncrement: true)
 *  - RecordSet::upsertAllByUniqueKey()
 *
 * "Burn-free" is verified by id contiguity: the atomic upsert (MySQL ON DUPLICATE KEY UPDATE,
 * PostgreSQL and SQLite ON CONFLICT DO UPDATE) allocates and discards an auto-increment value on
 * each conflicting write, leaving a gap — the burn-free paths must not. Runs on all three
 * backends.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase|\Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase
 */
trait UpsertByUniqueKeyCases
{
    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        return [UpsertByUniqueKeyRecord::class];
    }

    public function testSingleUpsertInsertsThenUpdatesWithoutBurningAutoIncrement(): void
    {
        $a = new UpsertByUniqueKeyRecord();
        $a->code = 'alpha';
        $a->name = 'Alpha';
        $a->upsertByUniqueKey('uniq_code', ['name'], preserveAutoIncrement: true);
        $this->assertSame(1, $a->id);

        // Same conflict key → UPDATE in place, PK back-filled, no new row.
        $b = new UpsertByUniqueKeyRecord();
        $b->code = 'alpha';
        $b->name = 'Alpha v2';
        $b->upsertByUniqueKey('uniq_code', ['name'], preserveAutoIncrement: true);
        $this->assertSame(1, $b->id);
        $this->assertSame(1, UpsertByUniqueKeyRecord::countWhere('1=1'));
        $this->assertSame('Alpha v2', UpsertByUniqueKeyRecord::findOne('code = ?', ['alpha'])?->name);

        // A genuinely-new row gets id 2 — contiguous, proving the update above burned nothing.
        $c = new UpsertByUniqueKeyRecord();
        $c->code = 'beta';
        $c->name = 'Beta';
        $c->upsertByUniqueKey('uniq_code', ['name'], preserveAutoIncrement: true);
        $this->assertSame(2, $c->id);
    }

    public function testDefaultUpsertBurnsAutoIncrement(): void
    {
        // Contrast: the atomic upsert default DOES burn the auto-increment on conflict — the
        // insert attempt advances the counter before the conflict is resolved to an UPDATE. True
        // on MySQL/MariaDB, PostgreSQL, and SQLite (with AUTOINCREMENT) alike.
        $a = new UpsertByUniqueKeyRecord();
        $a->code = 'alpha';
        $a->name = 'Alpha';
        $a->upsertByUniqueKey('uniq_code', ['name']); // id 1

        $b = new UpsertByUniqueKeyRecord();
        $b->code = 'alpha';
        $b->name = 'Alpha v2';
        $b->upsertByUniqueKey('uniq_code', ['name']); // conflict → burns id 2

        $c = new UpsertByUniqueKeyRecord();
        $c->code = 'beta';
        $c->name = 'Beta';
        $c->upsertByUniqueKey('uniq_code', ['name']); // id 3 — the gap is the burn

        // The default path doesn't back-fill the PK onto the object, so read it from the DB:
        // beta landed on id 3, not 2 — id 2 was burned by the conflicting re-upsert of alpha.
        $this->assertSame(3, UpsertByUniqueKeyRecord::findOne('code = ?', ['beta'])?->id);
    }

    public function testBatchUpsertAllByUniqueKeyIsBurnFree(): void
    {
        // Seed two rows (ids 1, 2).
        (new UpsertByUniqueKeyRecord())->withCode('alpha', 'Alpha')->upsertByUniqueKey('uniq_code', ['name'], preserveAutoIncrement: true);
        (new UpsertByUniqueKeyRecord())->withCode('beta', 'Beta')->upsertByUniqueKey('uniq_code', ['name'], preserveAutoIncrement: true);

        // Batch: two existing (update) + one new (insert).
        $alpha = (new UpsertByUniqueKeyRecord())->withCode('alpha', 'Alpha v2');
        $beta = (new UpsertByUniqueKeyRecord())->withCode('beta', 'Beta v2');
        $gamma = (new UpsertByUniqueKeyRecord())->withCode('gamma', 'Gamma');

        (new RecordSet([$alpha, $beta, $gamma]))->upsertAllByUniqueKey('uniq_code');

        // Existing rows updated in place, PKs back-filled to the existing ids.
        $this->assertSame(1, $alpha->id);
        $this->assertSame(2, $beta->id);
        $this->assertSame('Alpha v2', UpsertByUniqueKeyRecord::findOne('code = ?', ['alpha'])?->name);
        $this->assertSame('Beta v2', UpsertByUniqueKeyRecord::findOne('code = ?', ['beta'])?->name);

        // The new row got id 3 (contiguous) — the two updates burned nothing.
        $this->assertSame(3, $gamma->id);
        $this->assertSame(3, UpsertByUniqueKeyRecord::countWhere('1=1'));

        // And the next genuinely-new row is id 4 — still no gap.
        $delta = (new UpsertByUniqueKeyRecord())->withCode('delta', 'Delta');
        (new RecordSet([$delta]))->upsertAllByUniqueKey('uniq_code');
        $this->assertSame(4, $delta->id);
    }

    public function testIsDuplicateKeyErrorDetectsUniqueViolation(): void
    {
        $session = Record::connection()->session;
        (new UpsertByUniqueKeyRecord())->withCode('dup', 'First')->save();

        try {
            // Raw second insert with the same unique `code` → unique-constraint violation
            // (SQLSTATE 23000 on MySQL/MariaDB, 23505 on PostgreSQL).
            $session->exec('INSERT INTO attrecord_upsert (code, name) VALUES (?, ?)', ['dup', 'Second']);
            $this->fail('expected a duplicate-key violation');
        } catch (\Throwable $e) {
            $this->assertTrue($session->isDuplicateKeyError($e));
        }
    }

    // -----------------------------------------------------------------
    // Expression / RawSql SET, with the incoming()/stored() helpers
    // -----------------------------------------------------------------

    /** Build the portable "keep the stored value unless the incoming one is non-empty" SET for a column. */
    private function keepIfIncomingNonEmpty(string $column): RawSql
    {
        return new RawSql(
            sprintf(
                'CASE WHEN %1$s <> ? THEN %1$s ELSE %2$s END',
                UpsertByUniqueKeyRecord::incoming($column),   // VALUES(`name`) | EXCLUDED."name"
                UpsertByUniqueKeyRecord::stored($column),      // `name`         | "name"
            ),
            [''],   // the empty-string comparison, bound (not inlined) so quoting can't bite
        );
    }

    public function testExpressionSetPreservesOrReplacesViaIncomingAndStored(): void
    {
        (new UpsertByUniqueKeyRecord())->withCode('slug', 'Original')->upsertByUniqueKey('uniq_code', ['name']);
        $this->assertSame('Original', UpsertByUniqueKeyRecord::findOne('code = ?', ['slug'])?->name);

        $keep = $this->keepIfIncomingNonEmpty('name');

        // Empty incoming → the CASE keeps the stored value.
        (new UpsertByUniqueKeyRecord())->withCode('slug', '')->upsertByUniqueKey('uniq_code', ['name' => $keep]);
        $this->assertSame('Original', UpsertByUniqueKeyRecord::findOne('code = ?', ['slug'])?->name, 'empty incoming preserved the stored name');

        // Non-empty incoming → the CASE takes the incoming value.
        (new UpsertByUniqueKeyRecord())->withCode('slug', 'Updated')->upsertByUniqueKey('uniq_code', ['name' => $keep]);
        $this->assertSame('Updated', UpsertByUniqueKeyRecord::findOne('code = ?', ['slug'])?->name, 'non-empty incoming replaced the stored name');

        // Exactly one row throughout — every write was an upsert on the same conflict key.
        $this->assertSame(1, UpsertByUniqueKeyRecord::countWhere('1=1'));
    }

    public function testExpressionSetMixesPlainAndExpressionColumns(): void
    {
        $seed = new UpsertByUniqueKeyRecord();
        $seed->code = 'mix';
        $seed->name = 'Seed';
        $seed->note = 'keep-me';
        $seed->upsertByUniqueKey('uniq_code', ['name', 'note']);

        // `name` = plain incoming copy; `note` = keep-unless-non-empty.
        $in = new UpsertByUniqueKeyRecord();
        $in->code = 'mix';
        $in->name = 'Fresh';
        $in->note = '';   // empty → the expression keeps 'keep-me'
        $in->upsertByUniqueKey('uniq_code', ['name', 'note' => $this->keepIfIncomingNonEmpty('note')]);

        $row = UpsertByUniqueKeyRecord::findOne('code = ?', ['mix']);
        $this->assertNotNull($row);
        $this->assertSame('Fresh', $row->name, 'plain list entry took the incoming value');
        $this->assertSame('keep-me', $row->note, 'expression entry preserved the stored value');
    }

    public function testExpressionSetRejectedWithPreserveAutoIncrement(): void
    {
        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'x';
        $r->name = 'y';

        $this->expectException(AttrecordException::class);
        $r->upsertByUniqueKey('uniq_code', ['name' => new RawSql('CURRENT_TIMESTAMP')], preserveAutoIncrement: true);
    }

    public function testExpressionSetUnknownColumnThrows(): void
    {
        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'x';
        $r->name = 'y';

        $this->expectException(SchemaException::class);
        $r->upsertByUniqueKey('uniq_code', ['no_such_col' => new RawSql('1')]);
    }
}
