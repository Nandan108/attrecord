<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UpsertByUniqueKeyRecord;
use PHPUnit\Framework\TestCase;

/**
 * SQL-shape and param-order verification for expression/RawSql SET in upsertByUniqueKey,
 * per dialect, without a live database.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class UpsertExpressionSetSqlTest extends TestCase
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
        $this->session->reset(); // drop any connection-init statements
        TableSchema::clearCache();
    }

    public function testMysqlRendersOnDuplicateKeyUpdateWithExpressionAndIncomingRef(): void
    {
        $this->connect(new MysqlDialect());

        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'slug';
        $r->name = 'incoming-name';
        $r->upsertByUniqueKey('uniq_code', [
            'name' => new RawSql(
                sprintf(
                    'CASE WHEN %1$s <> ? THEN %1$s ELSE %2$s END',
                    UpsertByUniqueKeyRecord::incoming('name'),
                    UpsertByUniqueKeyRecord::stored('name'),
                ),
                [''],
            ),
        ]);

        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('INSERT INTO `attrecord_upsert`', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('VALUES(`name`)', $sql, 'incoming() renders VALUES(col) on MySQL');
        $this->assertStringContainsString('CASE WHEN VALUES(`name`) <> ? THEN VALUES(`name`) ELSE `name` END', $sql);

        // Param order: the INSERT VALUES params (code, name, note) first, then the SET-expression param ('').
        $this->assertSame(['slug', 'incoming-name', null, ''], $this->session->lastParams());
    }

    public function testPgsqlRendersOnConflictDoUpdateWithExcludedRef(): void
    {
        $this->connect(new PgsqlDialect());

        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'slug';
        $r->name = 'incoming-name';
        $r->upsertByUniqueKey('uniq_code', [
            'name' => new RawSql(
                sprintf(
                    'COALESCE(NULLIF(%1$s, ?), %2$s)',
                    UpsertByUniqueKeyRecord::incoming('name'),
                    UpsertByUniqueKeyRecord::stored('name'),
                ),
                [''],
            ),
        ]);

        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('ON CONFLICT ("code") DO UPDATE SET', $sql);
        $this->assertStringContainsString('COALESCE(NULLIF(EXCLUDED."name", ?), "name")', $sql, 'incoming() renders EXCLUDED.col on PG');
        $this->assertSame(['slug', 'incoming-name', null, ''], $this->session->lastParams());
    }

    public function testSqliteRendersExcludedLowercase(): void
    {
        $this->connect(new SqliteDialect());

        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'slug';
        $r->name = 'n';
        $r->upsertByUniqueKey('uniq_code', ['name' => new RawSql(UpsertByUniqueKeyRecord::incoming('name'))]);

        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('ON CONFLICT ("code") DO UPDATE SET "name" = excluded."name"', $sql);
    }

    public function testPlainListEntryStillRendersIncomingCopy(): void
    {
        $this->connect(new MysqlDialect());

        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'slug';
        $r->name = 'n';
        $r->upsertByUniqueKey('uniq_code', ['name']);   // legacy list form

        $sql = (string) $this->session->lastSql();
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $sql);
        // No SET-expression params — only the INSERT VALUES.
        $this->assertSame(['slug', 'n', null], $this->session->lastParams());
    }

    public function testStringKeyRequiresRawSqlValue(): void
    {
        $this->connect(new MysqlDialect());
        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'x';
        $r->name = 'y';

        // A string key marks an expression entry, so its value must be a RawSql — a bare string is
        // rejected (never treated as raw SQL, which would be an injection footgun).
        $this->expectException(\Nandan108\Attrecord\Exception\SchemaException::class);
        $r->upsertByUniqueKey('uniq_code', ['name' => 'just a string']);
    }

    public function testListEntryMustBeAColumnName(): void
    {
        $this->connect(new MysqlDialect());
        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'x';
        $r->name = 'y';

        // An int-keyed entry is a plain column name; a RawSql there is meaningless.
        $this->expectException(\Nandan108\Attrecord\Exception\SchemaException::class);
        $r->upsertByUniqueKey('uniq_code', [new RawSql('1')]);
    }

    public function testExpressionParamsInterleaveInSetColumnOrder(): void
    {
        $this->connect(new MysqlDialect());

        $r = new UpsertByUniqueKeyRecord();
        $r->code = 'slug';
        $r->name = 'n';
        // Two expression columns, each carrying a param — they must bind in map iteration order,
        // after the INSERT VALUES params.
        $r->upsertByUniqueKey('uniq_code', [
            'name' => new RawSql('CONCAT(`name`, ?)', ['-a']),
            'note' => new RawSql('CONCAT(COALESCE(`note`, ?), ?)', ['', '-b']),
        ]);

        $this->assertSame(
            ['slug', 'n', null, '-a', '', '-b'],
            $this->session->lastParams(),
            'INSERT params, then name-expr param, then note-expr params in order',
        );
    }
}
