<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\DdlForeignKeyRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlGeneratedColumnRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlOrderRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * PostgreSQL DDL-emission coverage, mirroring {@see MysqlDialectCreateTableTest} with
 * PG-specific expectations: BIGSERIAL PKs, no UNSIGNED, BYTEA, NUMERIC, BOOLEAN, JSONB,
 * Enum-as-TEXT-plus-CHECK, secondary indexes and comments as separate statements, and no
 * engine/charset/collation or ON UPDATE clauses.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class PgsqlDialectCreateTableTest extends TestCase
{
    private PgsqlDialect $dialect;

    #[\Override]
    protected function setUp(): void
    {
        $this->dialect = new PgsqlDialect();
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    // -----------------------------------------------------------------
    // Header / primary key / table-option omission
    // -----------------------------------------------------------------

    public function testEmitsHeaderAndNoMysqlTableOptions(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringStartsWith('CREATE TABLE "attrecord_ddl_orders" (', $sql);
        $this->assertStringNotContainsString('ENGINE=', $sql);
        $this->assertStringNotContainsString('CHARSET', $sql);
        $this->assertStringNotContainsString('COLLATE', $sql);
    }

    public function testIfNotExistsFlagEmitsConditionalCreate(): void
    {
        $schema = TableSchema::fromClass(DdlOrderRecord::class);

        $plain = $this->dialect->buildCreateTable($schema);
        $conditional = $this->dialect->buildCreateTable($schema, ifNotExists: true);

        $this->assertStringStartsWith('CREATE TABLE "', $plain);
        $this->assertStringNotContainsString('IF NOT EXISTS', $plain);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS "', $conditional);
        $this->assertStringContainsString('CREATE INDEX IF NOT EXISTS', $conditional);
    }

    public function testEmitsBigserialPrimaryKey(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        // BIGSERIAL implies NOT NULL + a sequence default — neither is emitted explicitly.
        $this->assertStringContainsString('"id" BIGSERIAL,', $sql);
        $this->assertStringNotContainsString('"id" BIGSERIAL NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY ("id")', $sql);
    }

    // -----------------------------------------------------------------
    // Column type mapping
    // -----------------------------------------------------------------

    public function testEmitsVarcharWithLiteralDefault(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"status" VARCHAR(20) NOT NULL DEFAULT \'pending\'', $sql);
    }

    public function testEmitsEnumAsTextWithCheckConstraint(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            '"payment_method" TEXT NOT NULL DEFAULT \'cash\' CHECK ("payment_method" IN (\'cash\', \'card\', \'wire\'))',
            $sql,
        );
    }

    public function testEmitsDecimalAsNumeric(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"total" NUMERIC(10, 2) NOT NULL DEFAULT 0', $sql);
    }

    public function testEmitsBoolAsBooleanWithBoolDefault(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"is_paid" BOOLEAN NOT NULL DEFAULT FALSE', $sql);
    }

    public function testEmitsDatetimeAsTimestampWithDefaultExpr(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql);
    }

    public function testDoesNotEmitOnUpdateClause(): void
    {
        // ON UPDATE CURRENT_TIMESTAMP has no PostgreSQL column-clause equivalent.
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"updated_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql);
        // The MySQL auto-update clause is absent (FK lines still legitimately carry ON UPDATE RESTRICT).
        $this->assertStringNotContainsString('ON UPDATE CURRENT_TIMESTAMP', $sql);
    }

    public function testEmitsNullableColumnWithoutNotNull(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"external_ref" VARCHAR(64),', $sql);
        $this->assertStringNotContainsString('"external_ref" VARCHAR(64) NOT NULL', $sql);
    }

    public function testEmitsBinaryAsBytea(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(BinaryPkLikeRecord::class));

        $this->assertStringContainsString('"id" BYTEA NOT NULL', $sql);
    }

    public function testEmitsUnsignedIntegerWithoutUnsigned(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        // customer_id is BigIntUnsigned — PostgreSQL has no unsigned, so plain BIGINT.
        $this->assertStringContainsString('"customer_id" BIGINT NOT NULL', $sql);
        $this->assertStringNotContainsString('UNSIGNED', $sql);
    }

    // -----------------------------------------------------------------
    // Generated columns
    // -----------------------------------------------------------------

    public function testEmitsGeneratedStoredColumn(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlGeneratedColumnRecord::class));

        $this->assertStringContainsString(
            '"scope_key" INTEGER GENERATED ALWAYS AS (COALESCE(scope_id, 0)) STORED',
            $sql,
        );
    }

    public function testVirtualGeneratedColumnRejected(): void
    {
        $record = new #[Table(name: 'attrecord_pg_virtual')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::IntUnsigned, generatedAs: 'COALESCE(x, 0)', generatedMode: GeneratedColumnMode::Virtual)]
            public int $g = 0;
        };

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('does not support VIRTUAL generated columns');
        $this->dialect->buildCreateTable(TableSchema::fromClass($record::class));
    }

    public function testSetTypeRejected(): void
    {
        $record = new #[Table(name: 'attrecord_pg_set')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::Set, enumValues: ['a', 'b'])]
            public string $flags = '';
        };

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('PostgreSQL has no SET type');
        $this->dialect->buildCreateTable(TableSchema::fromClass($record::class));
    }

    // -----------------------------------------------------------------
    // Unique keys / indexes / comments as separate statements
    // -----------------------------------------------------------------

    public function testEmitsUniqueConstraints(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('CONSTRAINT "uk_customer_date" UNIQUE ("customer_id", "created_at")', $sql);
        $this->assertStringContainsString('CONSTRAINT "uk_external_ref" UNIQUE ("external_ref")', $sql);
    }

    public function testEmitsSecondaryIndexAsSeparateStatement(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            'CREATE INDEX "idx_status_date" ON "attrecord_ddl_orders" ("status", "created_at")',
            $sql,
        );
    }

    public function testEmitsTableAndColumnCommentsAsSeparateStatements(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('COMMENT ON TABLE "attrecord_ddl_orders" IS \'Test orders table\'', $sql);
        $this->assertStringContainsString('COMMENT ON COLUMN "attrecord_ddl_orders"."status" IS \'Workflow state\'', $sql);
    }

    // -----------------------------------------------------------------
    // Foreign keys
    // -----------------------------------------------------------------

    public function testEmitsForeignKeyForManyToOneRelation(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            'CONSTRAINT "fk_ddl_orders_customer_id" FOREIGN KEY ("customer_id")'
                .' REFERENCES "attrecord_users" ("id")'
                .' ON DELETE CASCADE ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function testEmitsClassLevelForeignKeyToRecordlessTable(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlForeignKeyRecord::class));

        $this->assertStringContainsString(
            'CONSTRAINT "fk_ledger_slot_id" FOREIGN KEY ("slot_id") REFERENCES "attrecord_slots" ("id") ON DELETE SET NULL ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function testDoesNotEmitForeignKeyForRecordWithoutOwningRelations(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(UserRecord::class));

        $this->assertStringNotContainsString('FOREIGN KEY', $sql);
    }
}

/** @internal Binary (BYTEA) primary key fixture for the PG type-mapping test. */
#[Table(name: 'attrecord_pg_binary')]
final class BinaryPkLikeRecord extends Record
{
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';
}
