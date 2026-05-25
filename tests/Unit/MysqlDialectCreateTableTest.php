<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\MysqlTableOptions;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\DdlOrderRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/** @psalm-suppress PropertyNotSetInConstructor */
final class MysqlDialectCreateTableTest extends TestCase
{
    private MysqlDialect $dialect;

    #[\Override]
    protected function setUp(): void
    {
        $this->dialect = new MysqlDialect();
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    // -----------------------------------------------------------------
    // Header / column / table options
    // -----------------------------------------------------------------

    public function testEmitsHeaderAndDefaultTableOptionsWhenAttributeAbsent(): void
    {
        // DdlOrderRecord has no #[MysqlTableOptions] — dialect defaults apply.
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringStartsWith('CREATE TABLE `attrecord_ddl_orders` (', $sql);
        $this->assertStringContainsString(') ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
        $this->assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $sql);
        $this->assertStringContainsString("COMMENT='Test orders table'", $sql);
    }

    public function testMysqlTableOptionsAttributeOverridesAllThreeDefaults(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlMysqlOptionsRecord::class));

        $this->assertStringContainsString(') ENGINE=Memory', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=latin1', $sql);
        $this->assertStringContainsString('COLLATE=latin1_swedish_ci', $sql);
    }

    public function testMysqlTableOptionsAttributeOverridesOnlySpecifiedFields(): void
    {
        // DdlPartialMysqlOptionsRecord overrides ENGINE only; CHARSET and COLLATE
        // must fall back to dialect defaults (NOT to a hardcoded value on the attribute).
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlPartialMysqlOptionsRecord::class));

        $this->assertStringContainsString(') ENGINE=Memory', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
        $this->assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $sql);
    }

    public function testEmitsAutoIncrementPrimaryKey(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
    }

    public function testEmitsVarcharWithLengthAndLiteralDefault(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            "`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'Workflow state'",
            $sql,
        );
    }

    public function testEmitsEnumWithValues(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            "`payment_method` ENUM('cash', 'card', 'wire') NOT NULL DEFAULT 'cash'",
            $sql,
        );
    }

    public function testEmitsDecimalWithPrecisionScaleAndNumericDefault(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('`total` DECIMAL(10, 2) NOT NULL DEFAULT 0', $sql);
    }

    public function testEmitsBoolAsTinyint1WithBoolDefault(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('`is_paid` TINYINT(1) NOT NULL DEFAULT 0', $sql);
    }

    public function testEmitsDatetimeWithDefaultExpr(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            $sql,
        );
    }

    public function testEmitsOnUpdateClause(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            $sql,
        );
    }

    public function testEmitsNullableColumnWithoutNotNull(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('`external_ref` VARCHAR(64),', $sql);
        $this->assertStringNotContainsString('`external_ref` VARCHAR(64) NOT NULL', $sql);
    }

    // -----------------------------------------------------------------
    // Unique keys / indexes
    // -----------------------------------------------------------------

    public function testEmitsClassLevelCompositeUniqueKey(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            'UNIQUE KEY `uk_customer_date` (`customer_id`, `created_at`)',
            $sql,
        );
    }

    public function testEmitsPropertyLevelUniqueKey(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('UNIQUE KEY `uk_external_ref` (`external_ref`)', $sql);
    }

    public function testEmitsClassLevelCompositeIndex(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            'KEY `idx_status_date` (`status`, `created_at`)',
            $sql,
        );
        // Ensure it is not emitted as UNIQUE KEY.
        $this->assertStringNotContainsString('UNIQUE KEY `idx_status_date`', $sql);
    }

    // -----------------------------------------------------------------
    // Foreign keys
    // -----------------------------------------------------------------

    public function testEmitsForeignKeyForManyToOneRelation(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            'CONSTRAINT `fk_ddl_orders_customer_id` FOREIGN KEY (`customer_id`)'
                .' REFERENCES `attrecord_users` (`id`)'
                .' ON DELETE CASCADE ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function testDoesNotEmitForeignKeyForUserRecordWithoutOwningRelations(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(UserRecord::class));

        $this->assertStringNotContainsString('FOREIGN KEY', $sql);
    }

    // -----------------------------------------------------------------
    // Validation errors
    // -----------------------------------------------------------------

    public function testVarcharWithoutLengthThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/requires `length`/');

        TableSchema::fromClass(DdlInvalidVarcharRecord::class);
    }

    public function testDecimalWithoutPrecisionThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/requires both `precision` and `scale`/');

        TableSchema::fromClass(DdlInvalidDecimalRecord::class);
    }

    public function testEnumWithoutValuesThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/requires a non-empty `enumValues`/');

        TableSchema::fromClass(DdlInvalidEnumRecord::class);
    }

    public function testDefaultAndDefaultExprMutuallyExclusive(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/mutually exclusive/');

        TableSchema::fromClass(DdlConflictingDefaultRecord::class);
    }

    public function testClassLevelUniqueKeyRequiresColumns(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/requires a non-empty `columns`/');

        TableSchema::fromClass(DdlBadClassUniqueKeyRecord::class);
    }

    public function testPropertyLevelUniqueKeyForbidsColumns(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/must not specify the `columns`/');

        TableSchema::fromClass(DdlBadPropertyUniqueKeyRecord::class);
    }

    public function testClassLevelUniqueKeyReferencesMissingColumn(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/references column "ghost"/');

        TableSchema::fromClass(DdlPhantomColumnUniqueKeyRecord::class);
    }
}

// -----------------------------------------------------------------
// Inline fixtures for failure-mode tests (kept here so they're cheap to scan)
// -----------------------------------------------------------------

/** @internal */
#[Table(name: 'attrecord_invalid_varchar')]
final class DdlInvalidVarcharRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar)] // missing length
    public string $name = '';
}

/** @internal */
#[Table(name: 'attrecord_invalid_decimal')]
final class DdlInvalidDecimalRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Decimal)] // missing precision/scale
    public float $amount = 0.0;
}

/** @internal */
#[Table(name: 'attrecord_invalid_enum')]
final class DdlInvalidEnumRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Enum)] // missing enumValues
    public string $kind = '';
}

/** @internal */
#[Table(name: 'attrecord_conflicting_default')]
final class DdlConflictingDefaultRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Int, default: 5, defaultExpr: '42')] // both set
    public int $value = 0;
}

/** @internal */
#[Table(name: 'attrecord_bad_class_uk')]
#[UniqueKey('uk_empty')] // missing columns
final class DdlBadClassUniqueKeyRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

/** @internal */
#[Table(name: 'attrecord_bad_property_uk')]
final class DdlBadPropertyUniqueKeyRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 10)]
    #[UniqueKey('uk_thing', columns: ['name'])] // forbidden at property level
    public string $name = '';
}

/** @internal */
#[Table(name: 'attrecord_phantom_uk')]
#[UniqueKey('uk_phantom', columns: ['ghost'])] // ghost is not a declared column
final class DdlPhantomColumnUniqueKeyRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 10)]
    public string $name = '';
}

/** @internal Exercises #[MysqlTableOptions] overriding all three table-level fields. */
#[Table(name: 'attrecord_mysql_opts')]
#[MysqlTableOptions(engine: 'Memory', charset: 'latin1', collation: 'latin1_swedish_ci')]
final class DdlMysqlOptionsRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

/** @internal Exercises partial override — engine only; charset/collation fall back to dialect defaults. */
#[Table(name: 'attrecord_partial_mysql_opts')]
#[MysqlTableOptions(engine: 'Memory')]
final class DdlPartialMysqlOptionsRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}
