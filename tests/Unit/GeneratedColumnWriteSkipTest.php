<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\DdlGeneratedColumnRecord;
use PHPUnit\Framework\TestCase;

/**
 * Verify that database-generated columns are excluded from INSERT and UPDATE
 * statements emitted by Record::save(). The DB computes their value on every
 * write, so emitting them client-side would either fail (MySQL rejects writes
 * to GENERATED ALWAYS columns) or silently drift from the formula.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class GeneratedColumnWriteSkipTest extends TestCase
{
    private CapturingDbSession $session;

    #[\Override]
    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        Record::setConnection(new Connection($this->session, new MysqlDialect()));
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    public function testInsertExcludesGeneratedColumn(): void
    {
        $record = new DdlGeneratedColumnRecord();
        $record->scope_id = 42;
        $record->scope_key = 99; // would-be tampered value — must not reach the DB
        $record->value = 'sku-1';
        $record->save();

        $sql = (string) $this->session->lastSql();

        $this->assertStringContainsString('INSERT INTO `attrecord_gen_col`', $sql);
        $this->assertStringContainsString('`scope_id`', $sql);
        $this->assertStringContainsString('`value`', $sql);
        $this->assertStringNotContainsString('`scope_key`', $sql);
    }

    public function testUpdateExcludesGeneratedColumn(): void
    {
        $record = DdlGeneratedColumnRecord::hydrateFromArray([
            'id'        => 7,
            'scope_id'  => 42,
            'scope_key' => 42,
            'value'     => 'sku-1',
        ]);
        $record->scope_id = 100;
        $record->value = 'sku-2';
        $record->save();

        $sql = (string) $this->session->lastSql();

        $this->assertStringContainsString('UPDATE `attrecord_gen_col`', $sql);
        $this->assertStringContainsString('`scope_id` = ?', $sql);
        $this->assertStringContainsString('`value` = ?', $sql);
        $this->assertStringNotContainsString('`scope_key` = ?', $sql);
    }
}
