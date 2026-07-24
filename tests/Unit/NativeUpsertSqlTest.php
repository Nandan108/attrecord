<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Enum\UpsertStrategy;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UpsertByUniqueKeyRecord;
use PHPUnit\Framework\TestCase;

/**
 * SQL-shape + statement-count verification for the native single-statement bulk upsert
 * ({@see UpsertStrategy::Native}) per dialect, without a live database.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class NativeUpsertSqlTest extends TestCase
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

    /** Two keyed (PK-carrying) records, so they route through the native upsert. */
    private function keyedSet(): RecordSet
    {
        $a = new UpsertByUniqueKeyRecord();
        $a->id = 1;
        $a->code = 'a';
        $a->name = 'A';
        $b = new UpsertByUniqueKeyRecord();
        $b->id = 2;
        $b->code = 'b';
        $b->name = 'B';

        return new RecordSet([$a, $b]);
    }

    /**
     * SQL statements logged (transactional() adds no BEGIN/COMMIT rows in the capturing double).
     *
     * @return list<string>
     */
    private function sqlCalls(): array
    {
        return array_map(static fn (array $c): string => $c['sql'], $this->session->allCalls());
    }

    public function testMysqlNativeIsOneStatementWithOnDuplicateKeyUpdate(): void
    {
        $this->connect(new MysqlDialect());

        $result = $this->keyedSet()->upsertAll(strategy: UpsertStrategy::Native);

        $calls = $this->sqlCalls();
        $this->assertCount(1, $calls, 'native upsert is a single statement — no SELECT … FOR UPDATE');
        $sql = $calls[0];
        $this->assertStringContainsString('INSERT INTO `attrecord_upsert` (`id`, `code`, `name`) VALUES', $sql);
        $this->assertStringContainsString('(1, ', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `code` = VALUES(`code`), `name` = VALUES(`name`)', $sql);
        $this->assertStringNotContainsString('FOR UPDATE', $sql);
        $this->assertNotNull($result);
        $this->assertSame(0, $result->updated, 'native reports the raw affected count in inserted; updated is 0');
    }

    public function testLockedDefaultIsStillThreeStatements(): void
    {
        $this->connect(new MysqlDialect());

        $this->keyedSet()->upsertAll(); // default strategy = Locked

        $calls = $this->sqlCalls();
        $this->assertCount(3, $calls, 'the deadlock-safe path stays INSERT IGNORE → SELECT FOR UPDATE → UPDATE');
        $this->assertStringContainsString('INSERT IGNORE', $calls[0]);
        $this->assertStringContainsString('FOR UPDATE', $calls[1]);
        $this->assertStringStartsWith('UPDATE', $calls[2]);
    }

    public function testPgsqlNativeUsesOnConflictPkDoUpdateWithExcluded(): void
    {
        $this->connect(new PgsqlDialect());

        $this->keyedSet()->upsertAll(strategy: UpsertStrategy::Native);

        $calls = $this->sqlCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET "code" = EXCLUDED."code", "name" = EXCLUDED."name"', $calls[0]);
    }

    public function testSqliteNativeUsesOnConflictPkDoUpdateWithExcludedLowercase(): void
    {
        $this->connect(new SqliteDialect());

        $this->keyedSet()->upsertAll(strategy: UpsertStrategy::Native);

        $calls = $this->sqlCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET "code" = excluded."code", "name" = excluded."name"', $calls[0]);
    }

    public function testNativeEmptyUpdateSetDegradesToInsertOrIgnore(): void
    {
        // A dialect-level check: no update columns → insert-or-ignore, not an error.
        $dialect = new MysqlDialect();
        $sql = $dialect->buildBulkUpsertSql('t', ['id'], ['id'], [['1'], ['2']], []);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `id` = `id`', $sql);

        $pg = new PgsqlDialect();
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $pg->buildBulkUpsertSql('t', ['id'], ['id'], [['1']], []));

        $sqlite = new SqliteDialect();
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $sqlite->buildBulkUpsertSql('t', ['id'], ['id'], [['1']], []));
    }
}
