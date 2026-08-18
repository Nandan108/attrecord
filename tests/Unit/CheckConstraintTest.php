<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Check;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\DdlCheckRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Class-level #[Check]: emission on all three dialects, the naming rules, and the declarations
 * that are refused at schema-build time.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class CheckConstraintTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    #[Test]
    public function everyDialectEmitsBothConstraintsWithTheExpressionVerbatim(): void
    {
        $schema = TableSchema::fromClass(DdlCheckRecord::class);

        foreach ([new MysqlDialect(), new PgsqlDialect(), new SqliteDialect()] as $dialect) {
            $sql = $dialect->buildCreateTable($schema);
            $where = $dialect::class;

            // The expression reaches the engine exactly as declared — no dialect parses or
            // rewrites it, which is what lets an author use each engine's full expression language.
            self::assertStringContainsString("CHECK (kind = 'unit' OR tracking = 'none')", $sql, $where);
            self::assertStringContainsString("CHECK (kind <> 'batch' OR parent_id IS NOT NULL)", $sql, $where);
            self::assertStringContainsString('CONSTRAINT', $sql, $where);
        }
    }

    #[Test]
    public function theConstraintLineIsRenderedByOneAuthorityForCreateAndAlterAlike(): void
    {
        // Same seam as buildColumnLine()/buildForeignKeyLine(): whatever CREATE emits inline is
        // what `ALTER TABLE … ADD <fragment>` must emit, forever, or the migration companion
        // drifts from the producer.
        $schema = TableSchema::fromClass(DdlCheckRecord::class);
        $dialect = new MysqlDialect();

        foreach ($schema->checks as $check) {
            self::assertStringContainsString($dialect->buildCheckLine($check), $dialect->buildCreateTable($schema));
        }
    }

    #[Test]
    public function theEmittedNameCarriesTheDeclaredNameAndKeepsTheDeclaredOneReadable(): void
    {
        $checks = array_values(TableSchema::fromClass(DdlCheckRecord::class)->checks);

        self::assertCount(2, $checks);
        self::assertSame('tracking_unit_only', $checks[0]->declaredName);
        self::assertStringStartsWith('chk_tracking_unit_only_', $checks[0]->constraintName);
        self::assertSame("kind = 'unit' OR tracking = 'none'", $checks[0]->expression);
    }

    #[Test]
    public function distinctPrefixesYieldDistinctConstraintNames(): void
    {
        // MySQL scopes CHECK constraint names per *database* — `ERROR 3822 Duplicate check
        // constraint name` — so two installs sharing one database (a `wp_` site and a `blog_` site
        // on shared hosting) must not derive the same name from the same declaration. Exactly the
        // foreign-key situation, and the same digest answers it.
        self::assertNotSame($this->namesForPrefix('wp_'), $this->namesForPrefix('blog_'));
        self::assertNotSame($this->namesForPrefix('wp_'), $this->namesForPrefix(''));
    }

    #[Test]
    public function editingTheExpressionRenamesTheConstraint(): void
    {
        // The load-bearing property for convergence: no engine stores the expression as written
        // (MySQL adds charset introducers, PostgreSQL adds casts), so comparing live against
        // declared cannot tell an edited rule from a re-spelled one. Digesting the expression into
        // the name means an edited rule *is* a new constraint, and name-only diffing then adds it
        // and drops its predecessor with no expression comparison anywhere.
        $before = TableSchema::fromClass(CheckRenameBeforeRecord::class)->checks;
        $after = TableSchema::fromClass(CheckRenameAfterRecord::class)->checks;

        self::assertNotSame(array_keys($before), array_keys($after));
        self::assertSame('same_name', array_values($before)[0]->declaredName);
        self::assertSame('same_name', array_values($after)[0]->declaredName);
    }

    #[Test]
    public function reindentingTheExpressionIsNotASchemaChange(): void
    {
        // Whitespace is normalized before digesting, so reformatting a long expression does not
        // present as a constraint to drop and re-add.
        self::assertSame(
            array_keys(TableSchema::fromClass(CheckRenameBeforeRecord::class)->checks),
            array_keys(TableSchema::fromClass(CheckReindentedRecord::class)->checks),
        );
    }

    #[Test]
    public function theEmittedNameStaysWithinTheIdentifierLimit(): void
    {
        Record::setTablePrefix('a_very_long_multisite_table_prefix_');
        TableSchema::clearCache();

        foreach (TableSchema::fromClass(CheckLongNameRecord::class)->checks as $name => $_check) {
            self::assertLessThanOrEqual(64, \strlen($name), $name);
        }
    }

    #[Test]
    public function aRepeatedNameIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('declared twice');
        TableSchema::fromClass(CheckDuplicateNameRecord::class);
    }

    #[Test]
    public function anEmptyExpressionIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('non-empty boolean SQL expression');
        TableSchema::fromClass(CheckEmptyExpressionRecord::class);
    }

    #[Test]
    public function aNameCollidingWithAnEnumColumnsCheckIsRefused(): void
    {
        // PostgreSQL and SQLite carry an enum column's member list in a CHECK named after the
        // column. Letting a #[Check] take that name would replace the member list with a rule and
        // silently drop the enum's enforcement on those two backends.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('enum members');
        TableSchema::fromClass(CheckEnumCollisionRecord::class);
    }

    /** @return list<string> */
    private function namesForPrefix(string $prefix): array
    {
        Record::setTablePrefix($prefix);
        TableSchema::clearCache();

        return array_keys(TableSchema::fromClass(DdlCheckRecord::class)->checks);
    }
}

/** @internal */
#[Table(name: 'attrecord_chk_rename')]
#[Check('same_name', 'qty > 0')]
final class CheckRenameBeforeRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;
}

/** @internal */
#[Table(name: 'attrecord_chk_rename')]
#[Check('same_name', 'qty >= 0')]
final class CheckRenameAfterRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;
}

/** @internal */
#[Table(name: 'attrecord_chk_rename')]
#[Check('same_name', "qty   >    0\n")]
final class CheckReindentedRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;
}

/** @internal */
#[Table(name: 'attrecord_chk_long')]
#[Check('a_deliberately_long_constraint_name_describing_the_whole_rule_at_length', 'qty > 0')]
final class CheckLongNameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;
}

/** @internal */
#[Table(name: 'attrecord_chk_dup')]
#[Check('twice', 'qty > 0')]
#[Check('twice', 'qty < 100')]
final class CheckDuplicateNameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;
}

/** @internal */
#[Table(name: 'attrecord_chk_empty')]
#[Check('blank', '   ')]
final class CheckEmptyExpressionRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

/** @internal */
#[Table(name: 'attrecord_chk_enum_collision')]
#[Check('chk_status_enum', "status <> ''")]
final class CheckEnumCollisionRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
    #[Column(ColumnType::Enum, enumValues: ['draft', 'live'])]
    public string $status = 'draft';
}
