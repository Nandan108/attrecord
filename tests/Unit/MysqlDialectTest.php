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
    // buildBulkUpsert
    // -----------------------------------------------------------------

    public function testBuildBulkUpsertSingleRow(): void
    {
        $sql = $this->dialect->buildBulkUpsert(
            tableName: 'users',
            columnNames: ['name', 'email'],
            pkColumnNames: ['id'],
            rows: [["'Alice'", "'a@example.com'"]],
            updateColumns: ['name', 'email'],
        );

        $this->assertStringContainsString('INSERT INTO `users`', $sql);
        $this->assertStringContainsString('`name`, `email`', $sql);
        $this->assertStringContainsString("'Alice' AS `name`, 'a@example.com' AS `email`", $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`name` = vals.`name`', $sql);
    }

    public function testBuildBulkUpsertMultipleRows(): void
    {
        $sql = $this->dialect->buildBulkUpsert(
            tableName: 'users',
            columnNames: ['name', 'email'],
            pkColumnNames: ['id'],
            rows: [
                ["'Alice'", "'a@example.com'"],
                ["'Bob'",   "'b@example.com'"],
            ],
            updateColumns: ['name', 'email'],
        );

        $this->assertStringContainsString('UNION ALL SELECT', $sql);
        $this->assertStringContainsString("'Bob', 'b@example.com'", $sql);
    }

    // -----------------------------------------------------------------

    private function col(ColumnType $type, bool $nullable = false): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'col',
            type: $type,
            nullable: $nullable,
            autoIncrement: false,
            trimOnSet: false,
            length: null,
            precision: null,
            scale: null,
        );
    }
}
