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
use Nandan108\Attrecord\Tests\Fixtures\DdlBadForeignKeyRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlForeignKeyRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlGeneratedColumnRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlNonRecordForeignKeyRecord;
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

    public function testConstructorDefaultsOverrideTheConstantsWhenAttributeAbsent(): void
    {
        // DdlOrderRecord has no #[MysqlTableOptions]; the instance defaults apply
        // in place of the DEFAULT_* constants — lets a consumer align DDL with the
        // host database's collation.
        $dialect = new MysqlDialect(
            defaultEngine: 'MyISAM',
            defaultCharset: 'utf8mb3',
            defaultCollation: 'utf8mb3_general_ci',
        );

        $sql = $dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(') ENGINE=MyISAM', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb3', $sql);
        $this->assertStringContainsString('COLLATE=utf8mb3_general_ci', $sql);
    }

    public function testMysqlTableOptionsAttributeStillWinsOverConstructorDefaults(): void
    {
        // Precedence: per-table #[MysqlTableOptions] > instance default > constant.
        $dialect = new MysqlDialect(defaultCollation: 'utf8mb4_0900_ai_ci');

        $sql = $dialect->buildCreateTable(TableSchema::fromClass(DdlMysqlOptionsRecord::class));

        $this->assertStringContainsString('COLLATE=latin1_swedish_ci', $sql);
    }

    public function testNullConstructorDefaultsFallBackToTheConstants(): void
    {
        // Explicitly-null fields must behave identically to the no-arg constructor.
        $dialect = new MysqlDialect(defaultCollation: null);

        $sql = $dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(') ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
        $this->assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $sql);
    }

    public function testIfNotExistsFlagEmitsConditionalCreate(): void
    {
        $schema = TableSchema::fromClass(DdlOrderRecord::class);

        $sqlPlain = $this->dialect->buildCreateTable($schema);
        $sqlConditional = $this->dialect->buildCreateTable($schema, ifNotExists: true);

        $this->assertStringStartsWith('CREATE TABLE `', $sqlPlain);
        $this->assertStringNotContainsString('IF NOT EXISTS', $sqlPlain);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `', $sqlConditional);
    }

    public function testEmitsAutoIncrementPrimaryKey(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
    }

    // -----------------------------------------------------------------
    // #[ForeignKey] — Record-less foreign keys
    // -----------------------------------------------------------------

    public function testForeignKeyAttributeEmitsConstraintToRecordlessTable(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlForeignKeyRecord::class));

        // Default target column is `id`; constraint name follows the fk_<table>_<col>
        // convention (the leading "attrecord_" segment is stripped to stay compact).
        $this->assertStringContainsString(
            'CONSTRAINT `fk_ledger_slot_id` FOREIGN KEY (`slot_id`) REFERENCES `attrecord_slots` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function testForeignKeyHonoursReferencesColumnOverride(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlForeignKeyRecord::class));

        $this->assertStringContainsString(
            'CONSTRAINT `fk_ledger_owner_id` FOREIGN KEY (`owner_id`) REFERENCES `attrecord_owners` (`owner_pk`) ON DELETE CASCADE ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function testForeignKeyAppliesTablePrefixToTarget(): void
    {
        Record::setTablePrefix('wp_');
        try {
            $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlForeignKeyRecord::class));
        } finally {
            Record::setTablePrefix('');
        }

        // The active prefix is applied to the Record-less FK target exactly as it is to
        // Record-backed targets, so the constraint resolves under a prefix.
        $this->assertStringContainsString('REFERENCES `wp_attrecord_slots` (`id`)', $sql);
        $this->assertStringContainsString('CREATE TABLE `wp_attrecord_ledger`', $sql);
    }

    public function testForeignKeyResolvesRecordClassTarget(): void
    {
        // references: UserRecord::class — table name and PK derived from the Record
        // (rename-safe), constraint emitted with no relation property.
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlForeignKeyRecord::class));

        $this->assertStringContainsString(
            'CONSTRAINT `fk_ledger_user_id` FOREIGN KEY (`user_id`) REFERENCES `attrecord_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function testForeignKeyClassTargetTracksPrefixAcrossChanges(): void
    {
        // Regression: a class-form target must reflect the CURRENT prefix on every resolution.
        // Only the class/table classification is memoised; the prefixed name is read fresh, so
        // resolving under one prefix must not leave a stale name for the next.
        try {
            Record::setTablePrefix('wp_');
            $prefixed = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlForeignKeyRecord::class));
            $this->assertStringContainsString('REFERENCES `wp_attrecord_users` (`id`)', $prefixed);

            Record::setTablePrefix('');
            $plain = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlForeignKeyRecord::class));
            $this->assertStringContainsString('REFERENCES `attrecord_users` (`id`)', $plain);
            $this->assertStringNotContainsString('wp_attrecord_users', $plain);
        } finally {
            Record::setTablePrefix('');
        }
    }

    public function testForeignKeyOnUndeclaredColumnThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('#[ForeignKey] column "nonexistent" is not a declared #[Column]');

        TableSchema::fromClass(DdlBadForeignKeyRecord::class);
    }

    public function testForeignKeyToNonRecordClassThrows(): void
    {
        // `references: \stdClass::class` is a class but not a Record — a mistake, not a table name.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('is a class but not a');

        $this->dialect->buildCreateTable(TableSchema::fromClass(DdlNonRecordForeignKeyRecord::class));
    }

    public function testEmitsVarcharWithLengthAndLiteralDefault(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            "`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'Workflow state'",
            $sql,
        );
    }

    public function testEmitsGeneratedStoredColumn(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlGeneratedColumnRecord::class));

        // Generated clause appears between the column type and any further
        // column constraints. NULL/NOT NULL is intentionally omitted on
        // generated columns (MariaDB rejects an explicit NOT NULL here, and
        // the expression already determines nullability).
        $this->assertStringContainsString(
            '`scope_key` INT UNSIGNED GENERATED ALWAYS AS (IFNULL(scope_id, 0)) STORED',
            $sql,
        );
        $this->assertStringNotContainsString('STORED NOT NULL', $sql);
        // Compound UNIQUE key mixing a real column and the generated column.
        $this->assertStringContainsString('UNIQUE KEY `uq_scope_value` (`scope_key`, `value`)', $sql);
    }

    public function testGeneratedColumnRejectsDefaultClause(): void
    {
        $record = new #[Table(name: 'attrecord_gen_bad')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::IntUnsigned, generatedAs: 'IFNULL(x, 0)', default: 5)]
            public int $bad = 0;
        };

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('a generated column cannot also declare `default`');
        TableSchema::fromClass($record::class);
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

    // -----------------------------------------------------------------
    // DateTime / Timestamp precision
    // -----------------------------------------------------------------

    public function testDateTimeWithPrecisionEmitsFractionalSeconds(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlDateTimePrecisionRecord::class));

        $this->assertStringContainsString('`created_at` DATETIME(6) NOT NULL', $sql);
        $this->assertStringContainsString('`recorded_at` TIMESTAMP(3) NOT NULL', $sql);
        $this->assertStringContainsString('`updated_at` DATETIME NOT NULL', $sql);
    }

    public function testDateTimePrecisionOutOfRangeThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/precision must be between 0 and 6/');

        TableSchema::fromClass(DdlBadDateTimePrecisionRecord::class);
    }

    public function testScaleOnDateTimeThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/does not accept `scale`/');

        TableSchema::fromClass(DdlDateTimeWithScaleRecord::class);
    }

    public function testPrecisionOnVarcharThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/does not accept `precision`/');

        TableSchema::fromClass(DdlVarcharWithPrecisionRecord::class);
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

/** @internal Exercises DateTime(p) / Timestamp(p) precision rendering. */
#[Table(name: 'attrecord_datetime_precision')]
final class DdlDateTimePrecisionRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::Timestamp, precision: 3)]
    public ?\DateTimeImmutable $recorded_at = null;

    // No precision — emits bare DATETIME for the no-fractional-seconds case.
    #[Column(ColumnType::DateTime)]
    public ?\DateTimeImmutable $updated_at = null;
}

/** @internal precision > 6 on DateTime — rejected at schema build. */
#[Table(name: 'attrecord_bad_dt_precision')]
final class DdlBadDateTimePrecisionRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::DateTime, precision: 9)]
    public ?\DateTimeImmutable $created_at = null;
}

/** @internal scale on DateTime — rejected at schema build (scale is Decimal-only). */
#[Table(name: 'attrecord_dt_with_scale')]
final class DdlDateTimeWithScaleRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::DateTime, precision: 6, scale: 2)]
    public ?\DateTimeImmutable $created_at = null;
}

/** @internal precision on VarChar — rejected at schema build. */
#[Table(name: 'attrecord_varchar_with_precision')]
final class DdlVarcharWithPrecisionRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 10, precision: 5)]
    public string $name = '';
}
