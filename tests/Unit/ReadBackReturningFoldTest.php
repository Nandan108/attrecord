<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\InsertDefaultRecord;
use PHPUnit\Framework\TestCase;

/**
 * The read-back is folded into the write's RETURNING clause on a supporting dialect (one round-trip),
 * and falls back to a scoped SELECT on a dialect without RETURNING (MySQL/MariaDB).
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class ReadBackReturningFoldTest extends TestCase
{
    private CapturingDbSession $session;

    #[\Override]
    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    /** @return list<string> */
    private function sqls(): array
    {
        return array_map(static fn (array $c): string => $c['sql'], $this->session->allCalls());
    }

    public function testInsertFoldsReadBackIntoReturningOnSupportingDialect(): void
    {
        Record::setConnection(new Connection($this->session, new SqliteDialect()));
        $this->session->reset(); // drop connection-init PRAGMAs
        // The RETURNING row the DB would hand back: PK + the omitted NOT-NULL default.
        $this->session->queueFetchOne(['id' => 1, 'created_at' => '2026-01-01 00:00:00']);

        // status/note written; created_at (NOT-NULL default) omitted → auto reads it back.
        $rec = new InsertDefaultRecord();
        $rec->status = 'a';
        $rec->save();

        $sqls = $this->sqls();
        $this->assertCount(1, $sqls, 'read-back folded into the INSERT — no separate SELECT');
        $this->assertStringContainsString('INSERT INTO', $sqls[0]);
        $this->assertStringContainsString('RETURNING', $sqls[0]);
        $this->assertStringContainsString('"created_at"', $sqls[0], 'the diverged column is scoped into RETURNING');
        $this->assertStringNotContainsStringIgnoringCase('SELECT', $sqls[0]);

        // And the value landed in memory from that single statement.
        $this->assertInstanceOf(\DateTimeImmutable::class, self::col($rec, 'created_at'));
    }

    public function testInsertUsesScopedSelectOnNonReturningDialect(): void
    {
        Record::setConnection(new Connection($this->session, new MysqlDialect()));
        $this->session->reset(); // drop any connection-init statements
        $this->session->setNextInsertId(1);
        $this->session->queueFetchOne(['id' => 1, 'created_at' => '2026-01-01 00:00:00']);

        $rec = new InsertDefaultRecord();
        $rec->status = 'a';
        $rec->save();

        $sqls = $this->sqls();
        $this->assertCount(2, $sqls, 'MySQL: INSERT then a scoped read-back SELECT');
        $this->assertStringContainsString('INSERT INTO', $sqls[0]);
        $this->assertStringNotContainsStringIgnoringCase('RETURNING', $sqls[0]);
        $this->assertStringContainsString('SELECT', $sqls[1]);
    }

    public function testUpdateFoldsReadBackIntoReturningOnSupportingDialect(): void
    {
        Record::setConnection(new Connection($this->session, new SqliteDialect()));
        $rec = InsertDefaultRecord::hydrateFromArray([
            'id'         => 1,
            'status'     => 'a',
            'created_at' => '2026-01-01 00:00:00',
            'note'       => 'x',
        ]);
        $this->session->reset();
        // The UPDATE … RETURNING row: only the column we ask back.
        $this->session->queueFetchOne(['note' => 'y']);

        // Change note, and read it back explicitly (a targeted list also folds).
        $rec->note = 'y';
        $rec->save(readBack: ['note']);

        $sqls = $this->sqls();
        $this->assertCount(1, $sqls, 'read-back folded into the UPDATE — no separate SELECT');
        $this->assertStringContainsString('UPDATE', $sqls[0]);
        $this->assertStringContainsString('RETURNING', $sqls[0]);
        $this->assertStringNotContainsStringIgnoringCase('SELECT', $sqls[0]);
    }

    public function testNoReadBackMeansNoReturningAndNoSelect(): void
    {
        Record::setConnection(new Connection($this->session, new SqliteDialect()));
        $rec = InsertDefaultRecord::hydrateFromArray([
            'id'         => 1,
            'status'     => 'a',
            'created_at' => '2026-01-01 00:00:00',
            'note'       => 'x',
        ]);
        $this->session->reset();

        // A plain column change with nothing DB-computed to refresh: no RETURNING, no SELECT.
        $rec->note = 'y';
        $rec->save();

        $sqls = $this->sqls();
        $this->assertCount(1, $sqls, 'only the UPDATE runs');
        $this->assertStringContainsString('UPDATE', $sqls[0]);
        $this->assertStringNotContainsStringIgnoringCase('RETURNING', $sqls[0]);
    }

    private static function col(object $record, string $name): mixed
    {
        return $record->{$name};
    }
}
