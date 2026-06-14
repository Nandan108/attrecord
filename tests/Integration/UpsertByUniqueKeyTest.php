<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\UpsertByUniqueKeyRecord;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

/**
 * Covers the burn-free upsert-by-unique-key paths:
 *  - Record::upsertByUniqueKey(..., preserveAutoIncrement: true)
 *  - RecordSet::upsertAllByUniqueKey()
 *
 * "Burn-free" is verified by id contiguity: an INSERT … ON DUPLICATE KEY UPDATE allocates and
 * discards an auto-increment value on each conflicting write, leaving a gap; these paths must not.
 */
final class UpsertByUniqueKeyTest extends IntegrationTestCase
{
    protected static function createSchema(): void
    {
        static::$pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS `attrecord_upsert` (
                `id`   bigint unsigned NOT NULL AUTO_INCREMENT,
                `code` varchar(32)     NOT NULL,
                `name` varchar(100)    NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    protected static function truncateTables(): void
    {
        static::$pdo->exec('TRUNCATE TABLE `attrecord_upsert`');
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
        $this->assertSame('Alpha v2', UpsertByUniqueKeyRecord::findOne('`code` = ?', ['alpha'])?->name);

        // A genuinely-new row gets id 2 — contiguous, proving the update above burned nothing.
        $c = new UpsertByUniqueKeyRecord();
        $c->code = 'beta';
        $c->name = 'Beta';
        $c->upsertByUniqueKey('uniq_code', ['name'], preserveAutoIncrement: true);
        $this->assertSame(2, $c->id);
    }

    public function testDefaultUpsertBurnsAutoIncrement(): void
    {
        // Contrast: the atomic INSERT … ON DUPLICATE KEY UPDATE default DOES burn.
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
        $this->assertSame(3, UpsertByUniqueKeyRecord::findOne('`code` = ?', ['beta'])?->id);
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
        $this->assertSame('Alpha v2', UpsertByUniqueKeyRecord::findOne('`code` = ?', ['alpha'])?->name);
        $this->assertSame('Beta v2', UpsertByUniqueKeyRecord::findOne('`code` = ?', ['beta'])?->name);

        // The new row got id 3 (contiguous) — the two updates burned nothing.
        $this->assertSame(3, $gamma->id);
        $this->assertSame(3, UpsertByUniqueKeyRecord::countWhere('1=1'));

        // And the next genuinely-new row is id 4 — still no gap.
        $delta = (new UpsertByUniqueKeyRecord())->withCode('delta', 'Delta');
        (new RecordSet([$delta]))->upsertAllByUniqueKey('uniq_code');
        $this->assertSame(4, $delta->id);
    }
}
