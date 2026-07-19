<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Integration\Cases\RecordSetCases;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

/** @group mysql */
final class RecordSetMysqlTest extends IntegrationTestCase
{
    use RecordSetCases;

    /** Backend-specific: the deadlock-safe bulk-upsert SQL uses MySQL syntax. */
    public function testBuildUpsertAllSqlGeneratesMysqlSyntax(): void
    {
        $alice = $this->makeUser('Alice');
        $alice->name = 'Alice Updated';

        $upsert = (new RecordSet([$alice]))->buildUpsertAllSql();

        $this->assertNotNull($upsert);
        $this->assertStringContainsString('INSERT IGNORE INTO `attrecord_users`', $upsert->create);
        $this->assertStringContainsString('FOR UPDATE', $upsert->lock);
        $this->assertNotNull($upsert->update);
        $this->assertStringContainsString('UPDATE `attrecord_users`', $upsert->update);
    }
}
