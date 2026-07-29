<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\PrimaryKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use PHPUnit\Framework\TestCase;

/**
 * `#[PrimaryKey(columns: …)]` — composite keys, **DDL-only**.
 *
 * The feature is as much about what it refuses as what it emits. A table keyed `(a, b)` can now
 * be *described* in PHP so the DDL producer emits it and schema-evolution tooling can see it —
 * previously such a table needed hand-written DDL, which the differ cannot compare against
 * anything, so it sat outside the managed schema and drifted unobserved. But the CRUD paths all
 * assume one PK column, so they must fail loudly rather than address the wrong rows.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class CompositePrimaryKeyTest extends TestCase
{
    protected function setUp(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
        Record::setTablePrefix('');
    }

    // ---------------------------------------------------------------- schema

    public function testTheSchemaCarriesTheOrderedMemberList(): void
    {
        $schema = TableSchema::fromClass(CompositeStateRecord::class);

        self::assertSame(['subject_id', 'slot_id'], $schema->compositePk);
        self::assertSame(['subject_id', 'slot_id'], $schema->pkColumns());
    }

    /** Key order is physical index order, so it must survive exactly as declared. */
    public function testMemberOrderIsPreservedNotSorted(): void
    {
        self::assertSame(['zeta', 'alpha'], TableSchema::fromClass(ReverseOrderPkRecord::class)->pkColumns());
    }

    public function testAnOrdinaryRecordReportsItsSinglePkColumn(): void
    {
        $schema = TableSchema::fromClass(SinglePkRecord::class);

        self::assertNull($schema->compositePk);
        self::assertSame(['id'], $schema->pkColumns());
    }

    // ------------------------------------------------------------------- DDL

    /** @return iterable<string, array{object, string}> */
    public static function dialects(): iterable
    {
        yield 'mysql' => [new MysqlDialect(), 'PRIMARY KEY (`subject_id`, `slot_id`)'];
        yield 'pgsql' => [new PgsqlDialect(), 'PRIMARY KEY ("subject_id", "slot_id")'];
        yield 'sqlite' => [new SqliteDialect(), 'PRIMARY KEY ("subject_id", "slot_id")'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dialects')]
    public function testEveryDialectEmitsTheCompositeKey(object $dialect, string $expected): void
    {
        /** @var \Nandan108\Attrecord\SqlDialect $dialect */
        $sql = $dialect->buildCreateTable(TableSchema::fromClass(CompositeStateRecord::class));

        self::assertStringContainsString($expected, $sql);
        // Exactly one PRIMARY KEY clause — not one per member.
        self::assertSame(1, substr_count($sql, 'PRIMARY KEY'));
    }

    // ------------------------------------------------------------ validation

    public function testASingleColumnListIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('at least two columns');

        TableSchema::fromClass(OneMemberPkRecord::class);
    }

    public function testARepeatedMemberIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('more than once');

        TableSchema::fromClass(DuplicateMemberPkRecord::class);
    }

    public function testAMemberThatIsNotAColumnIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('no #[Column] with that column name');

        TableSchema::fromClass(UnknownMemberPkRecord::class);
    }

    public function testAnAutoIncrementMemberIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('auto-increment');

        TableSchema::fromClass(AutoIncMemberPkRecord::class);
    }

    /** Declaring both is a contradiction, not an override — silently picking one hides the bug. */
    public function testDeclaringBothPrimaryKeyFormsIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('use one');

        TableSchema::fromClass(BothPkFormsRecord::class);
    }

    // --------------------------------------------------- CRUD refuses, loudly

    public function testSaveRefuses(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('save() is not available');

        (new CompositeStateRecord())->save();
    }

    public function testDeleteRefuses(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('delete()');

        (new CompositeStateRecord())->delete();
    }

    public function testBulkWritesRefuse(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('upsertAll()');

        (new RecordSet([new CompositeStateRecord()]))->upsertAll();
    }

    /**
     * The most dangerous one to get wrong. LockSet orders ascending by `pk`, which on a composite
     * key is only the first member — an ordering that is neither total nor the one the table's
     * other access paths use. Two orderings of one table is the deadlock LockSet exists to stop.
     */
    public function testLockSetRefuses(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('LockSet::acquire()');

        LockSet::acquire(
            new Connection(new CapturingDbSession(), new MysqlDialect()),
            [CompositeStateRecord::class => [1]],
        );
    }

    /** The message has to say what to do instead, not merely that the door is shut. */
    public function testTheRefusalNamesTheKeyAndThePointOfTheFeature(): void
    {
        try {
            (new CompositeStateRecord())->save();
            self::fail('expected a SchemaException');
        } catch (SchemaException $e) {
            self::assertStringContainsString('subject_id, slot_id', $e->getMessage(), 'names the actual key');
            self::assertStringContainsString('raw SQL', $e->getMessage(), 'says what to do instead');
        }
    }

    /** Reads are *not* blocked: a SELECT by WHERE needs no primary key. */
    public function testReadBuildersAreNotBlocked(): void
    {
        $session = new CapturingDbSession();
        Record::setConnection(new Connection($session, new MysqlDialect()));

        // where() executes immediately and returns the result set.
        CompositeStateRecord::where('subject_id', 1);

        self::assertNotSame([], $session->allCalls(), 'a WHERE-based read still runs');
    }
}

// ---------------------------------------------------------------- fixtures

/** @internal a hot-path state table: one row per (subject, slot), read and written by raw SQL */
#[Table(name: 'attrecord_composite_state')]
#[PrimaryKey(columns: ['subject_id', 'slot_id'])]
#[LockTier(1)]
final class CompositeStateRecord extends Record
{
    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    #[Column(ColumnType::Binary, length: 16)]
    public string $slot_id = '';

    #[Column(ColumnType::IntUnsigned)]
    public int $quantity = 0;
}

/** @internal */
#[Table(name: 'attrecord_reverse_pk')]
#[PrimaryKey(columns: ['zeta', 'alpha'])]
final class ReverseOrderPkRecord extends Record
{
    #[Column(ColumnType::Int)]
    public int $alpha = 0;

    #[Column(ColumnType::Int)]
    public int $zeta = 0;
}

/** @internal */
#[Table(name: 'attrecord_single_pk')]
final class SinglePkRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

/** @internal */
#[Table(name: 'attrecord_one_member_pk')]
#[PrimaryKey(columns: ['only'])]
final class OneMemberPkRecord extends Record
{
    #[Column(ColumnType::Int)]
    public int $only = 0;
}

/** @internal */
#[Table(name: 'attrecord_dup_member_pk')]
#[PrimaryKey(columns: ['a', 'a'])]
final class DuplicateMemberPkRecord extends Record
{
    #[Column(ColumnType::Int)]
    public int $a = 0;
}

/** @internal */
#[Table(name: 'attrecord_unknown_member_pk')]
#[PrimaryKey(columns: ['a', 'nope'])]
final class UnknownMemberPkRecord extends Record
{
    #[Column(ColumnType::Int)]
    public int $a = 0;

    #[Column(ColumnType::Int)]
    public int $b = 0;
}

/** @internal */
#[Table(name: 'attrecord_autoinc_member_pk')]
#[PrimaryKey(columns: ['id', 'other'])]
final class AutoIncMemberPkRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Int)]
    public int $other = 0;
}

/** @internal */
#[Table(name: 'attrecord_both_pk_forms', primaryKey: 'a')]
#[PrimaryKey(columns: ['a', 'b'])]
final class BothPkFormsRecord extends Record
{
    #[Column(ColumnType::Int)]
    public int $a = 0;

    #[Column(ColumnType::Int)]
    public int $b = 0;
}
