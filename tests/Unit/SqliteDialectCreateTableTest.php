<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\BinaryPkRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlGeneratedColumnRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlOrderRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * SQLite DDL-emission coverage: INTEGER PRIMARY KEY AUTOINCREMENT inline (no separate PRIMARY
 * KEY), type affinities, Enum-as-TEXT-plus-CHECK, no comments, indexes as separate statements,
 * BLOB binary PK, generated columns (STORED and VIRTUAL), Set rejection, and connection-init.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class SqliteDialectCreateTableTest extends TestCase
{
    private SqliteDialect $dialect;

    #[\Override]
    protected function setUp(): void
    {
        $this->dialect = new SqliteDialect();
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    public function testEmitsInlineAutoincrementPkWithNoSeparatePrimaryKey(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringStartsWith('CREATE TABLE "attrecord_ddl_orders" (', $sql);
        $this->assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $this->assertStringNotContainsString('PRIMARY KEY ("id")', $sql);
    }

    public function testTypeAffinities(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"customer_id" INTEGER NOT NULL', $sql); // BigIntUnsigned → INTEGER
        $this->assertStringContainsString('"status" TEXT NOT NULL DEFAULT \'pending\'', $sql); // VarChar → TEXT
        $this->assertStringContainsString('"total" NUMERIC NOT NULL DEFAULT 0', $sql); // Decimal → NUMERIC
        $this->assertStringContainsString('"is_paid" INTEGER NOT NULL DEFAULT 0', $sql); // Bool → INTEGER
        $this->assertStringNotContainsString('UNSIGNED', $sql);
    }

    public function testEnumIsTextWithCheck(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            '"payment_method" TEXT NOT NULL DEFAULT \'cash\' CHECK ("payment_method" IN (\'cash\', \'card\', \'wire\'))',
            $sql,
        );
    }

    public function testDatetimeIsTextWithDefaultExprAndNoOnUpdate(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql);
        $this->assertStringContainsString('"updated_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql);
        // No MySQL auto-update clause (the FK line still legitimately carries ON UPDATE RESTRICT).
        $this->assertStringNotContainsString('ON UPDATE CURRENT_TIMESTAMP', $sql);
    }

    public function testNullableColumnHasNoNotNull(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('"external_ref" TEXT,', $sql);
        $this->assertStringNotContainsString('"external_ref" TEXT NOT NULL', $sql);
    }

    public function testUniqueConstraintsInlineAndNoComments(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString('CONSTRAINT "uk_customer_date" UNIQUE ("customer_id", "created_at")', $sql);
        $this->assertStringContainsString('CONSTRAINT "uk_external_ref" UNIQUE ("external_ref")', $sql);
        // SQLite has no COMMENT support.
        $this->assertStringNotContainsString('COMMENT', $sql);
    }

    public function testSecondaryIndexIsSeparateStatement(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            'CREATE INDEX "idx_status_date" ON "attrecord_ddl_orders" ("status", "created_at")',
            $sql,
        );
    }

    public function testForeignKeyInline(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlOrderRecord::class));

        $this->assertStringContainsString(
            'CONSTRAINT "fk_ddl_orders_customer_id" FOREIGN KEY ("customer_id")'
                .' REFERENCES "attrecord_users" ("id") ON DELETE CASCADE ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function testBinaryPkIsBlobWithSeparatePrimaryKey(): void
    {
        // Non-auto-increment PK: BLOB column + a separate PRIMARY KEY clause.
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(BinaryPkRecord::class));

        $this->assertStringContainsString('"id" BLOB NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY ("id")', $sql);
        $this->assertStringNotContainsString('AUTOINCREMENT', $sql);
    }

    public function testGeneratedStoredColumn(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(DdlGeneratedColumnRecord::class));

        $this->assertStringContainsString(
            '"scope_key" INTEGER GENERATED ALWAYS AS (COALESCE(scope_id, 0)) STORED',
            $sql,
        );
    }

    public function testVirtualGeneratedColumnIsSupported(): void
    {
        // Unlike PostgreSQL, SQLite supports VIRTUAL generated columns.
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(SqliteVirtualGenRecord::class));

        $this->assertStringContainsString('"g" INTEGER GENERATED ALWAYS AS (COALESCE(x, 0)) VIRTUAL', $sql);
    }

    public function testSetTypeRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('SQLite has no SET type');
        $this->dialect->buildCreateTable(TableSchema::fromClass(SqliteSetRecord::class));
    }

    public function testIfNotExistsFlag(): void
    {
        $schema = TableSchema::fromClass(DdlOrderRecord::class);

        $this->assertStringStartsWith('CREATE TABLE "', $this->dialect->buildCreateTable($schema));
        $conditional = $this->dialect->buildCreateTable($schema, ifNotExists: true);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS "', $conditional);
        $this->assertStringContainsString('CREATE INDEX IF NOT EXISTS', $conditional);
    }

    public function testNoForeignKeyForRecordWithoutOwningRelations(): void
    {
        $sql = $this->dialect->buildCreateTable(TableSchema::fromClass(UserRecord::class));
        $this->assertStringNotContainsString('FOREIGN KEY', $sql);
    }

    // -----------------------------------------------------------------
    // Connection init + capability flags
    // -----------------------------------------------------------------

    public function testDefaultConnectionInitStatements(): void
    {
        $this->assertSame(
            ['PRAGMA journal_mode=WAL', 'PRAGMA busy_timeout=5000', 'PRAGMA foreign_keys=ON'],
            (new SqliteDialect())->connectionInitStatements(),
        );
    }

    public function testConfigurableConnectionInitStatements(): void
    {
        $dialect = new SqliteDialect(journalMode: null, busyTimeoutMs: 2000, foreignKeys: false);
        $this->assertSame(['PRAGMA busy_timeout=2000'], $dialect->connectionInitStatements());
    }

    public function testCapabilityFlags(): void
    {
        $this->assertSame('', $this->dialect->forUpdateClause());
        $this->assertTrue($this->dialect->bindsBinaryAsLob());
        $this->assertSame('', $this->dialect->insertReturningSuffix('"id"'));
    }
}

/** @internal VIRTUAL generated column fixture for SQLite. */
#[Table(name: 'attrecord_sqlite_virtual')]
final class SqliteVirtualGenRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $x = null;

    #[Column(ColumnType::IntUnsigned, generatedAs: 'COALESCE(x, 0)', generatedMode: GeneratedColumnMode::Virtual)]
    public int $g = 0;
}

/** @internal SET column fixture — rejected by SQLite. */
#[Table(name: 'attrecord_sqlite_set')]
final class SqliteSetRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Set, enumValues: ['a', 'b'])]
    public string $flags = '';
}