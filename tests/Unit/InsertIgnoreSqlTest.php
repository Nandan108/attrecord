<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Enum\OnConflict;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UpsertByUniqueKeyRecord;
use PHPUnit\Framework\TestCase;

/**
 * SQL-shape verification for insert-or-ignore ({@see OnConflict::Ignore}) per dialect, without a
 * live database: the bulk {@see RecordSet::buildInsertAllSql()} path and the single-row
 * {@see Record::save()} path.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class InsertIgnoreSqlTest extends TestCase
{
    private CapturingDbSession $session;

    #[\Override]
    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    private function connect(SqlDialect $dialect): void
    {
        $this->session = new CapturingDbSession();
        Record::setConnection(new Connection($this->session, $dialect));
        $this->session->reset();
        TableSchema::clearCache();
    }

    private function newRow(string $code, string $name): UpsertByUniqueKeyRecord
    {
        $r = new UpsertByUniqueKeyRecord();
        $r->code = $code;
        $r->name = $name;

        return $r;
    }

    // -----------------------------------------------------------------
    // Bulk path: buildInsertAllSql()
    // -----------------------------------------------------------------

    public function testMysqlBulkIgnoreAppendsNoOpOnDuplicateKeyUpdate(): void
    {
        $this->connect(new MysqlDialect());
        $set = new RecordSet([$this->newRow('a', 'A'), $this->newRow('b', 'B')]);

        $sql = (string) $set->buildInsertAllSql(onConflict: OnConflict::Ignore);
        $this->assertStringContainsString('INSERT INTO `attrecord_upsert` (`code`, `name`) VALUES', $sql);
        // Only key conflicts are ignored — a no-op SET keyed on the first written column.
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `code` = `code`', $sql);
    }

    public function testMysqlBulkFailEmitsPlainInsert(): void
    {
        $this->connect(new MysqlDialect());
        $set = new RecordSet([$this->newRow('a', 'A')]);

        // Default (Fail) — no conflict clause; a collision surfaces as a DB error.
        $this->assertStringNotContainsString('ON DUPLICATE KEY UPDATE', (string) $set->buildInsertAllSql());
        $this->assertStringNotContainsString('ON DUPLICATE KEY UPDATE', (string) $set->buildInsertAllSql(onConflict: OnConflict::Fail));
    }

    public function testPgsqlBulkIgnoreAppendsOnConflictDoNothing(): void
    {
        $this->connect(new PgsqlDialect());
        $set = new RecordSet([$this->newRow('a', 'A')]);

        $sql = (string) $set->buildInsertAllSql(onConflict: OnConflict::Ignore);
        $this->assertStringContainsString('INSERT INTO "attrecord_upsert" ("code", "name") VALUES', $sql);
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $sql);
        $this->assertStringNotContainsString('INSERT OR IGNORE', $sql);
    }

    public function testSqliteBulkIgnoreAppendsOnConflictDoNothing(): void
    {
        $this->connect(new SqliteDialect());
        $set = new RecordSet([$this->newRow('a', 'A')]);

        $sql = (string) $set->buildInsertAllSql(onConflict: OnConflict::Ignore);
        // Deliberately ON CONFLICT DO NOTHING, not INSERT OR IGNORE (which would also swallow
        // NOT NULL / CHECK violations).
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $sql);
        $this->assertStringNotContainsString('INSERT OR IGNORE', $sql);
    }

    // -----------------------------------------------------------------
    // Single-row path: Record::save()
    // -----------------------------------------------------------------

    public function testMysqlSaveIgnoreEmitsNoOpOnDuplicateKeyUpdate(): void
    {
        $this->connect(new MysqlDialect());

        $this->newRow('a', 'A')->save(onConflict: OnConflict::Ignore);

        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('INSERT INTO `attrecord_upsert`', $sql);
        // The no-op SET keys on the first written column (a nullable `note` is written too here).
        $this->assertStringEndsWith('ON DUPLICATE KEY UPDATE `code` = `code`', $sql);
    }

    public function testPgsqlSaveIgnorePlacesDoNothingBeforeReturning(): void
    {
        $this->connect(new PgsqlDialect());

        $this->newRow('a', 'A')->save(onConflict: OnConflict::Ignore);

        // The ignore clause must precede RETURNING for the statement to parse.
        $this->assertStringContainsString('ON CONFLICT DO NOTHING RETURNING "id"', (string) $this->session->lastSql());
    }

    public function testMysqlSaveFailEmitsPlainInsert(): void
    {
        $this->connect(new MysqlDialect());

        $this->newRow('a', 'A')->save();

        $this->assertStringNotContainsString('ON DUPLICATE KEY UPDATE', (string) $this->session->lastSql());
    }
}
