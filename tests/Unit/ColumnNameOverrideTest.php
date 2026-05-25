<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\ColumnNameOverrideRecord;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the property-name / column-name split end-to-end (without a real DB).
 *
 * Asserts that:
 *   - schema-build correctly distinguishes columns (SQL identifiers) from properties (PHP names);
 *   - hydration assigns DB column values to the correct PHP property;
 *   - dirty-tracking & save SQL emit column names, not property names;
 *   - DDL emission uses column names everywhere;
 *   - SELECT by PK uses the column name in the WHERE clause.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class ColumnNameOverrideTest extends TestCase
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

    // -----------------------------------------------------------------
    // Schema layer
    // -----------------------------------------------------------------

    public function testSchemaExposesColumnNameAndPropertyNameSeparately(): void
    {
        $schema = TableSchema::fromClass(ColumnNameOverrideRecord::class);

        $this->assertSame('attrecord_override', $schema->tableName);
        $this->assertSame('order_id', $schema->pk);
        $this->assertSame('orderId', $schema->pkProp);

        $this->assertArrayHasKey('order_id', $schema->columns);
        $this->assertArrayHasKey('customer_id', $schema->columns);
        $this->assertArrayHasKey('external_ref', $schema->columns);
        $this->assertArrayNotHasKey('orderId', $schema->columns, 'columns must be keyed by column name, not property name');

        $this->assertSame('orderId', $schema->columns['order_id']->propertyName);
        $this->assertSame('customerId', $schema->columns['customer_id']->propertyName);
        $this->assertSame('externalRef', $schema->columns['external_ref']->propertyName);
    }

    public function testPropForResolvesColumnToProperty(): void
    {
        $schema = TableSchema::fromClass(ColumnNameOverrideRecord::class);

        $this->assertSame('customerId', $schema->propFor('customer_id'));
        $this->assertSame('externalRef', $schema->propFor('external_ref'));
    }

    // -----------------------------------------------------------------
    // Hydration
    // -----------------------------------------------------------------

    public function testHydrateFromArrayAssignsByColumnNameToCorrectProperty(): void
    {
        $record = ColumnNameOverrideRecord::hydrateFromArray([
            'order_id'     => 42,
            'customer_id'  => 7,
            'external_ref' => 'WOO-123',
            'created_at'   => '2026-05-25 10:00:00',
        ]);

        $this->assertSame(42, $record->orderId);
        $this->assertSame(7, $record->customerId);
        $this->assertSame('WOO-123', $record->externalRef);
        $this->assertInstanceOf(\DateTimeImmutable::class, $record->createdAt);
        $this->assertFalse($record->isDirty());
    }

    // -----------------------------------------------------------------
    // Dirty tracking
    // -----------------------------------------------------------------

    public function testChangingPhpPropertyMarksColumnDirty(): void
    {
        $record = ColumnNameOverrideRecord::hydrateFromArray([
            'order_id'     => 1,
            'customer_id'  => 7,
            'external_ref' => null,
            'created_at'   => '2026-05-25 10:00:00',
        ]);

        $record->customerId = 99;

        $this->assertTrue($record->isDirty());
        $dirty = $record->dirtyFields();
        $this->assertArrayHasKey('customer_id', $dirty, 'dirty map keys are column names');
        $this->assertArrayNotHasKey('customerId', $dirty);
    }

    // -----------------------------------------------------------------
    // Save SQL
    // -----------------------------------------------------------------

    public function testInsertEmitsColumnNamesNotPropertyNames(): void
    {
        $record = new ColumnNameOverrideRecord();
        $record->customerId = 7;
        $record->externalRef = 'WOO-1';
        $record->save();

        $sql = (string) $this->session->lastSql();

        $this->assertStringContainsString('INSERT INTO `attrecord_override`', $sql);
        $this->assertStringContainsString('`customer_id`', $sql);
        $this->assertStringContainsString('`external_ref`', $sql);
        $this->assertStringNotContainsString('customerId', $sql);
        $this->assertStringNotContainsString('externalRef', $sql);
    }

    public function testUpdateWherePkUsesColumnName(): void
    {
        $record = ColumnNameOverrideRecord::hydrateFromArray([
            'order_id'     => 42,
            'customer_id'  => 7,
            'external_ref' => null,
            'created_at'   => '2026-05-25 10:00:00',
        ]);
        $record->customerId = 8;
        $record->save();

        $sql = (string) $this->session->lastSql();

        $this->assertStringContainsString('UPDATE `attrecord_override`', $sql);
        $this->assertStringContainsString('WHERE `order_id` = ?', $sql);
        $this->assertStringContainsString('`customer_id` = ?', $sql);
    }

    // -----------------------------------------------------------------
    // DDL emission
    // -----------------------------------------------------------------

    public function testBuildCreateTableEmitsColumnNames(): void
    {
        $schema = TableSchema::fromClass(ColumnNameOverrideRecord::class);
        $sql = (new MysqlDialect())->buildCreateTable($schema);

        $this->assertStringContainsString('`order_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('`customer_id` BIGINT UNSIGNED NOT NULL', $sql);
        $this->assertStringContainsString('`external_ref` VARCHAR(64)', $sql);
        $this->assertStringContainsString('`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`order_id`)', $sql);

        // No PHP property names should leak into the SQL.
        $this->assertStringNotContainsString('orderId', $sql);
        $this->assertStringNotContainsString('customerId', $sql);
        $this->assertStringNotContainsString('externalRef', $sql);
        $this->assertStringNotContainsString('createdAt', $sql);
    }
}
