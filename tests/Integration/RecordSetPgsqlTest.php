<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Integration\Cases\RecordSetCases;
use Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase;

/** @group pgsql */
final class RecordSetPgsqlTest extends PgsqlIntegrationTestCase
{
    use RecordSetCases;

    /** Backend-specific: the deadlock-safe bulk-upsert SQL uses PostgreSQL ON CONFLICT syntax. */
    public function testBuildUpsertAllSqlGeneratesPgsqlSyntax(): void
    {
        $alice = $this->makeUser('Alice');
        $alice->name = 'Alice Updated';

        $upsert = (new RecordSet([$alice]))->buildUpsertAllSql();

        $this->assertNotNull($upsert);
        $this->assertStringContainsString('INSERT INTO "attrecord_users"', $upsert->create);
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $upsert->create);
        $this->assertStringContainsString('FOR UPDATE', $upsert->lock);
        $this->assertNotNull($upsert->update);
        $this->assertStringContainsString('UPDATE "attrecord_users"', $upsert->update);
    }
}
