<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use PHPUnit\Framework\TestCase;

/** @psalm-suppress PropertyNotSetInConstructor */
final class MysqlDialectTest extends TestCase
{
    private MysqlDialect $dialect;

    #[\Override]
    protected function setUp(): void
    {
        $this->dialect = new MysqlDialect();
    }

    // -----------------------------------------------------------------
    // quoteIdentifier
    // -----------------------------------------------------------------

    public function testQuoteIdentifierBasic(): void
    {
        $this->assertSame('`my_table`', $this->dialect->quoteIdentifier('my_table'));
    }

    public function testQuoteIdentifierEscapesBacktick(): void
    {
        $this->assertSame('`weird``name`', $this->dialect->quoteIdentifier('weird`name'));
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
        $this->assertSame('1', $this->dialect->toLiteral(true, $col));
    }

    public function testToLiteralBoolFalse(): void
    {
        $col = $this->col(ColumnType::Bool);
        $this->assertSame('0', $this->dialect->toLiteral(false, $col));
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
        $this->assertSame("'it\\'s'", $this->dialect->toLiteral("it's", $col));
    }

    public function testToLiteralStringEscapesBackslash(): void
    {
        $col = $this->col(ColumnType::VarChar);
        $this->assertSame("'C:\\\\path'", $this->dialect->toLiteral('C:\\path', $col));
    }

    public function testToLiteralBinary(): void
    {
        $col = $this->col(ColumnType::Binary);
        $this->assertSame("X'0102ff'", $this->dialect->toLiteral("\x01\x02\xff", $col));
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
    // buildBulkInsert
    // -----------------------------------------------------------------

    public function testBuildBulkInsertSingleRow(): void
    {
        $sql = $this->dialect->buildBulkInsert(
            tableName: 'products',
            columnNames: ['name', 'stock'],
            rows: [["'Widget'", '10']],
        );

        $this->assertStringContainsString('INSERT INTO `products`', $sql);
        $this->assertStringContainsString('`name`, `stock`', $sql);
        $this->assertStringContainsString("('Widget', 10)", $sql);
        $this->assertStringNotContainsString('IGNORE', $sql);
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

    public function testBuildUpsertSqlCreateIsInsertIgnore(): void
    {
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'products',
            pkColumn: 'id',
            columnNames: ['id', 'name', 'stock'],
            rows: [['42', "'Widget'", '10']],
            updateColumns: ['name', 'stock'],
        );

        $this->assertStringContainsString('INSERT IGNORE INTO `products`', $upsert->create);
        $this->assertStringContainsString('`id`, `name`, `stock`', $upsert->create);
        $this->assertStringContainsString("(42, 'Widget', 10)", $upsert->create);
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

        $this->assertStringContainsString('SELECT `id` FROM `products`', $upsert->lock);
        $this->assertStringContainsString('WHERE `id` IN (42, 7)', $upsert->lock);
        $this->assertStringContainsString('ORDER BY `id` ASC FOR UPDATE', $upsert->lock);
    }

    // -----------------------------------------------------------------
    // buildUpsertSql — update step
    // -----------------------------------------------------------------

    public function testBuildUpsertSqlUpdateUsesDerivedTableJoin(): void
    {
        // No dirty info → every column is uniform → written directly from the derived table, no mask.
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
        $this->assertStringContainsString('UPDATE `products`', $upsert->update);
        $this->assertStringContainsString('JOIN (', $upsert->update);
        $this->assertStringContainsString('ON `products`.`id` = u.`id`', $upsert->update);
        $this->assertStringContainsString("SELECT 42 AS `id`, 'Widget' AS `name`, 10 AS `stock`", $upsert->update);
        $this->assertStringContainsString("UNION ALL SELECT 7, 'Gadget', 5", $upsert->update);
        $this->assertStringContainsString('`products`.`name` = u.`name`', $upsert->update);
        $this->assertStringContainsString('`products`.`stock` = u.`stock`', $upsert->update);
        $this->assertStringNotContainsString('_m0', $upsert->update); // all uniform → no mask column
    }

    public function testBuildUpsertSqlSparseColumnGatedByMaskBit(): void
    {
        // Row 42 changed name + stock; row 7 changed name only. `name` is uniform (both rows) → written
        // directly. `stock` is sparse (only row 42) → gated by mask bit 1, so row 7 (mask 0) keeps its
        // live value instead of being clobbered.
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'products',
            pkColumn: 'id',
            columnNames: ['id', 'name', 'stock'],
            rows: [
                ['42', "'Widget'", '10'],
                ['7',  "'Gadget'", '5'],
            ],
            updateColumns: ['name', 'stock'],
            rowDirtyColumns: [
                ['name' => true, 'stock' => true],
                ['name' => true],
            ],
        );

        $this->assertNotNull($upsert->update);
        $this->assertStringContainsString('`products`.`name` = u.`name`', $upsert->update);
        $this->assertStringContainsString('`products`.`stock` = IF(u.`_m0` & 1, u.`stock`, `products`.`stock`)', $upsert->update);
        // derived table carries the mask: row 42 sets the bit (1), row 7 does not (0)
        $this->assertStringContainsString('1 AS `_m0`', $upsert->update);
        $this->assertStringContainsString('UNION ALL SELECT 7, 0,', $upsert->update);
    }

    public function testBuildUpsertSqlAllUniformNeedsNoMask(): void
    {
        // No dirty info → the single column is uniform → direct assignment, no mask, no IF.
        $upsert = $this->dialect->buildUpsertSql(
            tableName: 'products',
            pkColumn: 'id',
            columnNames: ['id', 'stock'],
            rows: [['42', '10'], ['7', '5']],
            updateColumns: ['stock'],
        );

        $this->assertNotNull($upsert->update);
        $this->assertStringContainsString('`products`.`stock` = u.`stock`', $upsert->update);
        $this->assertStringNotContainsString('_m0', $upsert->update);
        $this->assertStringNotContainsString('IF(', $upsert->update);
    }

    public function testBuildUpsertSqlMultiMaskSpillsBeyond63Columns(): void
    {
        // 64 sparse columns → bits 0..63. One integer holds 63 usable bits (0..62), so bit 63 spills
        // into a second mask column `_m1` (bit 0). This is the multi-mask arithmetic's boundary.
        $columns = ['id'];
        $updateColumns = [];
        $row0 = ['1'];
        $row1 = ['2'];
        $dirty0 = [];
        for ($i = 0; $i < 64; ++$i) {
            $columns[] = "c{$i}";
            $updateColumns[] = "c{$i}";
            $row0[] = (string) $i;
            $row1[] = (string) ($i + 100);
            $dirty0["c{$i}"] = true; // changed on row 0 only → sparse (needs a mask bit)
        }

        $upsert = $this->dialect->buildUpsertSql(
            tableName: 't',
            pkColumn: 'id',
            columnNames: $columns,
            rows: [$row0, $row1],
            updateColumns: $updateColumns,
            rowDirtyColumns: [$dirty0, []],
        );

        $this->assertNotNull($upsert->update);
        $this->assertStringContainsString('AS `_m0`', $upsert->update);
        $this->assertStringContainsString('AS `_m1`', $upsert->update);
        // Column ordinal 62 uses the top usable bit of _m0 (1 << 62); ordinal 63 spills to _m1 bit 0.
        $this->assertStringContainsString('`t`.`c62` = IF(u.`_m0` & '.(1 << 62).', u.`c62`, `t`.`c62`)', $upsert->update);
        $this->assertStringContainsString('`t`.`c63` = IF(u.`_m1` & 1, u.`c63`, `t`.`c63`)', $upsert->update);
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
        $this->assertStringContainsString('INSERT IGNORE', $upsert->create);
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

    public function testLikeEscapeSuffixIsEmpty(): void
    {
        $this->assertSame('', $this->dialect->likeEscapeSuffix());
    }

    // -----------------------------------------------------------------

    private function col(ColumnType $type, bool $nullable = false): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'col',
            propertyName: 'col',
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
