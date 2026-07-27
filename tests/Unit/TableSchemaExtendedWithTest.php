<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\DdlOrderRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * {@see TableSchema::extendedWith()} — describing columns a class cannot declare because the set
 * is only known at runtime.
 */
final class TableSchemaExtendedWithTest extends TestCase
{
    protected function setUp(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    private static function varchar(string $name, int $length = 64): ColumnDefinition
    {
        return new ColumnDefinition(
            name: $name,
            propertyName: $name,
            type: ColumnType::VarChar,
            nullable: false,
            autoIncrement: false,
            trimOnSave: null,
            length: $length,
            precision: null,
            scale: null,
            default: '',
        );
    }

    public function testAddedColumnsAndIndexesJoinTheDeclaredOnes(): void
    {
        $schema = TableSchema::fromClass(UserRecord::class)->extendedWith(
            columns: ['dim_loc' => self::varchar('dim_loc')],
            indexes: ['idx_dim_loc' => ['active', 'dim_loc']],
        );

        self::assertSame(['id', 'name', 'email', 'active', 'dim_loc'], $schema->columnNames());
        self::assertSame(['active', 'dim_loc'], $schema->indexes['idx_dim_loc']);
        self::assertSame('dim_loc', $schema->column('dim_loc')->name);
    }

    public function testTheOriginalSchemaIsUntouched(): void
    {
        $original = TableSchema::fromClass(UserRecord::class);

        $original->extendedWith(columns: ['dim_loc' => self::varchar('dim_loc')]);

        self::assertNotContains('dim_loc', $original->columnNames(), 'derivation must not mutate');
    }

    public function testDerivedSchemaEmitsTheAddedColumnsInItsDdl(): void
    {
        $schema = TableSchema::fromClass(UserRecord::class)->extendedWith(
            columns: ['dim_loc' => self::varchar('dim_loc')],
            indexes: ['idx_dim_loc' => ['active', 'dim_loc']],
            uniqueKeys: ['uniq_dim_loc' => ['dim_loc']],
        );

        $sql = (new MysqlDialect())->buildCreateTable($schema);

        self::assertStringContainsString("`dim_loc` VARCHAR(64) NOT NULL DEFAULT ''", $sql);
        self::assertStringContainsString('KEY `idx_dim_loc` (`active`, `dim_loc`)', $sql);
        self::assertStringContainsString('UNIQUE KEY `uniq_dim_loc` (`dim_loc`)', $sql);
    }

    /**
     * The identity of the class is what makes this a *derivation* rather than a hand-built schema:
     * everything the Record declares — its table, primary key, reflection data — carries over, so
     * the result stays usable everywhere a normal schema is.
     */
    public function testDerivedSchemaKeepsTheClassIdentity(): void
    {
        $original = TableSchema::fromClass(UserRecord::class);
        $derived = $original->extendedWith(columns: ['dim_loc' => self::varchar('dim_loc')]);

        self::assertSame($original->tableName, $derived->tableName);
        self::assertSame($original->pk, $derived->pk);
        self::assertSame($original->pkProp, $derived->pkProp);
        self::assertSame($original->reflProperties, $derived->reflProperties);
        self::assertSame($original->foreignKeys, $derived->foreignKeys);
        self::assertContains('dim_loc', $derived->dataColumnNames, 'and the added column is a data column');
    }

    public function testAddingNothingIsIdentity(): void
    {
        $original = TableSchema::fromClass(UserRecord::class);
        $derived = $original->extendedWith();

        self::assertEquals($original->columns, $derived->columns);
        self::assertEquals($original->indexes, $derived->indexes);
        self::assertEquals($original->uniqueKeys, $derived->uniqueKeys);
    }

    /**
     * Colliding with a declared name means the caller thinks it is contributing something the
     * class does not have. Winning or losing that silently would be equally confusing.
     */
    public function testCollidingWithADeclaredColumnThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('cannot add column "email"');

        TableSchema::fromClass(UserRecord::class)->extendedWith(columns: ['email' => self::varchar('email')]);
    }

    public function testCollidingWithADeclaredIndexThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('cannot add index "idx_status_date"');

        TableSchema::fromClass(DdlOrderRecord::class)->extendedWith(indexes: ['idx_status_date' => ['id']]);
    }

    public function testCollidingWithADeclaredUniqueKeyThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('cannot add unique key "uk_customer_date"');

        TableSchema::fromClass(DdlOrderRecord::class)->extendedWith(uniqueKeys: ['uk_customer_date' => ['id']]);
    }
}
