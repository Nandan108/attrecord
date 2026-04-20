<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use PHPUnit\Framework\TestCase;

/** @psalm-suppress PropertyNotSetInConstructor */
final class PgsqlDialectTest extends TestCase
{
    private PgsqlDialect $dialect;

    #[\Override]
    protected function setUp(): void
    {
        $this->dialect = new PgsqlDialect();
    }

    // -----------------------------------------------------------------
    // quoteIdentifier
    // -----------------------------------------------------------------

    public function testQuoteIdentifierBasic(): void
    {
        $this->assertSame('"my_table"', $this->dialect->quoteIdentifier('my_table'));
    }

    public function testQuoteIdentifierEscapesDoubleQuote(): void
    {
        $this->assertSame('"weird""name"', $this->dialect->quoteIdentifier('weird"name'));
    }

    // -----------------------------------------------------------------
    // toLiteral
    // -----------------------------------------------------------------

    public function testToLiteralNull(): void
    {
        $col = $this->col(ColumnType::VarChar, nullable: true);
        $this->assertSame('NULL', $this->dialect->toLiteral(null, $col));
    }

    public function testToLiteralBoolTrue(): void
    {
        $col = $this->col(ColumnType::Bool);
        $this->assertSame('TRUE', $this->dialect->toLiteral(true, $col));
    }

    public function testToLiteralBoolFalse(): void
    {
        $col = $this->col(ColumnType::Bool);
        $this->assertSame('FALSE', $this->dialect->toLiteral(false, $col));
    }

    public function testToLiteralInt(): void
    {
        $col = $this->col(ColumnType::Int);
        $this->assertSame('42', $this->dialect->toLiteral(42, $col));
    }

    public function testToLiteralFloat(): void
    {
        $col = $this->col(ColumnType::Decimal);
        $this->assertSame('3.14', $this->dialect->toLiteral(3.14, $col));
    }

    public function testToLiteralString(): void
    {
        $col = $this->col(ColumnType::VarChar);
        $this->assertSame("'hello'", $this->dialect->toLiteral('hello', $col));
    }

    public function testToLiteralStringEscapesSingleQuote(): void
    {
        $col = $this->col(ColumnType::VarChar);
        // PG escaping: single quote → two single quotes (no backslash)
        $this->assertSame("'it''s'", $this->dialect->toLiteral("it's", $col));
    }

    public function testToLiteralStringBackslashIsLiteral(): void
    {
        $col = $this->col(ColumnType::VarChar);
        // PG with standard_conforming_strings=on: backslash is not an escape
        $this->assertSame("'C:\\path'", $this->dialect->toLiteral('C:\\path', $col));
    }

    public function testToLiteralBinary(): void
    {
        $col = $this->col(ColumnType::Binary);
        $this->assertSame("decode('0102ff', 'hex')", $this->dialect->toLiteral("\x01\x02\xff", $col));
    }

    public function testToLiteralDatetime(): void
    {
        $col = $this->col(ColumnType::DateTime);
        $dt = new \DateTimeImmutable('2024-03-15 10:30:00');
        $this->assertSame("'2024-03-15 10:30:00'", $this->dialect->toLiteral($dt, $col));
    }

    public function testToLiteralDate(): void
    {
        $col = $this->col(ColumnType::Date);
        $dt = new \DateTimeImmutable('2024-03-15');
        $this->assertSame("'2024-03-15'", $this->dialect->toLiteral($dt, $col));
    }

    // -----------------------------------------------------------------
    // insertReturningSuffix
    // -----------------------------------------------------------------

    public function testInsertReturningSuffixContainsReturning(): void
    {
        $suffix = $this->dialect->insertReturningSuffix('"id"');
        $this->assertSame('RETURNING "id"', $suffix);
    }

    // -----------------------------------------------------------------
    // buildBulkInsert
    // -----------------------------------------------------------------

    public function testBuildBulkInsertSingleRow(): void
    {
        $sql = $this->dialect->buildBulkInsert(
            tableName: 'products',
            columnNames: ['name', 'stock'],
            rows: [["'Widget'", '10']],
        );

        $this->assertStringContainsString('INSERT INTO "products"', $sql);
        $this->assertStringContainsString('"name", "stock"', $sql);
        $this->assertStringContainsString("('Widget', 10)", $sql);
        $this->assertStringNotContainsString('IGNORE', $sql);
        $this->assertStringNotContainsString('CONFLICT', $sql);
    }

    public function testBuildBulkInsertMultipleRows(): void
    {
        $sql = $this->dialect->buildBulkInsert(
            tableName: 'products',
            columnNames: ['name', 'stock'],
            rows: [
                ["'Widget'", '10'],
                ["'Gadget'", '5'],
            ],
        );

        $this->assertStringContainsString("('Widget', 10)", $sql);
        $this->assertStringContainsString("('Gadget', 5)", $sql);
    }

    // -----------------------------------------------------------------
    // buildUpsertSql — create step
    // -----------------------------------------------------------------

    public function testBuildUpsertSqlCreateUsesOnConflictDoNothing(): void
    {
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'products',
            pkColumn: 'id',
            columnNames: ['id', 'name', 'stock'],
            rows: [['42', "'Widget'", '10']],
            updateColumns: ['name', 'stock'],
        );

        $this->assertStringContainsString('INSERT INTO "products"', $upsert->create);
        $this->assertStringContainsString('"id", "name", "stock"', $upsert->create);
        $this->assertStringContainsString("(42, 'Widget', 10)", $upsert->create);
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $upsert->create);
        $this->assertStringNotContainsString('IGNORE', $upsert->create);
    }

    public function testBuildUpsertSqlCreateMultipleRows(): void
    {
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'products',
            pkColumn: 'id',
            columnNames: ['id', 'name'],
            rows: [['1', "'A'"], ['2', "'B'"]],
            updateColumns: ['name'],
        );

        $this->assertStringContainsString("(1, 'A')", $upsert->create);
        $this->assertStringContainsString("(2, 'B')", $upsert->create);
    }

    // -----------------------------------------------------------------
    // buildUpsertSql — lock step
    // -----------------------------------------------------------------

    public function testBuildUpsertSqlLockSelectsForUpdate(): void
    {
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'products',
            pkColumn: 'id',
            columnNames: ['id', 'name'],
            rows: [['42', "'Widget'"], ['7', "'Gadget'"]],
            updateColumns: ['name'],
        );

        $this->assertStringContainsString('SELECT "id" FROM "products"', $upsert->lock);
        $this->assertStringContainsString('WHERE "id" IN (42, 7)', $upsert->lock);
        $this->assertStringContainsString('ORDER BY "id" ASC FOR UPDATE', $upsert->lock);
    }

    // -----------------------------------------------------------------
    // buildUpsertSql — update step
    // -----------------------------------------------------------------

    public function testBuildUpsertSqlUpdateContainsCaseExpression(): void
    {
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'products',
            pkColumn: 'id',
            columnNames: ['id', 'name', 'stock'],
            rows: [
                ['42', "'Widget'", '10'],
                ['7',  "'Gadget'", '5'],
            ],
            updateColumns: ['name', 'stock'],
        );

        $this->assertNotNull($upsert->update);
        $this->assertStringContainsString('UPDATE "products"', $upsert->update);
        $this->assertStringContainsString('CASE "id"', $upsert->update);
        $this->assertStringContainsString("WHEN 42 THEN 'Widget'", $upsert->update);
        $this->assertStringContainsString('WHEN 7 THEN 5', $upsert->update);
        $this->assertStringContainsString('WHERE "id" IN (42, 7)', $upsert->update);
    }

    public function testBuildUpsertSqlNoUpdateColumnsReturnsNullUpdate(): void
    {
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'lookup',
            pkColumn: 'id',
            columnNames: ['id'],
            rows: [['99']],
            updateColumns: [],
        );

        $this->assertNull($upsert->update);
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $upsert->create);
    }

    // -----------------------------------------------------------------
    // escapeLikeWildcards / likeEscapeSuffix
    // -----------------------------------------------------------------

    public function testEscapeLikeWildcardsPercent(): void
    {
        $this->assertSame('100\%', $this->dialect->escapeLikeWildcards('100%'));
    }

    public function testEscapeLikeWildcardsUnderscore(): void
    {
        $this->assertSame('file\_name', $this->dialect->escapeLikeWildcards('file_name'));
    }

    public function testEscapeLikeWildcardsBackslash(): void
    {
        $this->assertSame('C:\\\\path', $this->dialect->escapeLikeWildcards('C:\\path'));
    }

    public function testEscapeLikeWildcardsMixed(): void
    {
        $this->assertSame('50\% \_off\\\\ sale', $this->dialect->escapeLikeWildcards('50% _off\\ sale'));
    }

    public function testEscapeLikeWildcardsPlainStringUnchanged(): void
    {
        $this->assertSame('hello world', $this->dialect->escapeLikeWildcards('hello world'));
    }

    public function testLikeEscapeSuffixIsEscapeClause(): void
    {
        $this->assertSame(" ESCAPE '\\'", $this->dialect->likeEscapeSuffix());
    }

    // -----------------------------------------------------------------

    private function col(ColumnType $type, bool $nullable = false): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'col',
            type: $type,
            nullable: $nullable,
            autoIncrement: false,
            trimOnSave: null,
            length: null,
            precision: null,
            scale: null,
        );
    }
}
