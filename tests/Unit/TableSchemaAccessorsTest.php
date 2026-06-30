<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/** Covers the TableSchema accessor surface. */
final class TableSchemaAccessorsTest extends TestCase
{
    protected function setUp(): void
    {
        TableSchema::clearCache();
    }

    public function testColumnReturnsTheDefinition(): void
    {
        $schema = TableSchema::fromClass(UserRecord::class);

        $col = $schema->column('name');
        $this->assertInstanceOf(ColumnDefinition::class, $col);
        $this->assertSame('name', $col->name);
    }

    public function testColumnNamesListsEveryColumn(): void
    {
        $schema = TableSchema::fromClass(UserRecord::class);

        $this->assertSame(['id', 'name', 'email', 'active'], $schema->columnNames());
    }

    public function testPropForMapsColumnToProperty(): void
    {
        $schema = TableSchema::fromClass(UserRecord::class);

        $this->assertSame('name', $schema->propFor('name'));
    }

    public function testClearCacheForcesRebuild(): void
    {
        $first = TableSchema::fromClass(UserRecord::class);
        TableSchema::clearCache(UserRecord::class);
        $second = TableSchema::fromClass(UserRecord::class);

        // Distinct instances after a targeted cache clear (rebuilt from attributes).
        $this->assertNotSame($first, $second);
        $this->assertSame($first->tableName, $second->tableName);
    }
}
