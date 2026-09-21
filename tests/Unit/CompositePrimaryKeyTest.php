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
use Nandan108\Attrecord\Exception\RecordDeleteException;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use PHPUnit\Framework\TestCase;

/**
 * `#[PrimaryKey(columns: …)]` — composite keys.
 *
 * A table keyed `(a, b)` is *described* in PHP, so the DDL producer emits it and
 * schema-evolution tooling can see it; hand-written DDL is invisible to the differ and drifts
 * unobserved. Row identity then follows: the paths that address a row by key take the whole key,
 * and each path that cannot yet still refuses by name rather than matching on one member.
 *
 * The feature is as much about what it refuses as what it emits, and the refusals are the tests
 * worth reading twice — a partial key names a *set* of rows, so accepting one silently addresses
 * the wrong row on a read and rewrites several on a write.
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
        $schema = TableSchema::fromClass(CompositeKeyRecord::class);

        self::assertSame(['owner_id', 'item_id'], $schema->compositePk);
        self::assertSame(['owner_id', 'item_id'], $schema->pkColumns());
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
        yield 'mysql' => [new MysqlDialect(), 'PRIMARY KEY (`owner_id`, `item_id`)'];
        yield 'pgsql' => [new PgsqlDialect(), 'PRIMARY KEY ("owner_id", "item_id")'];
        yield 'sqlite' => [new SqliteDialect(), 'PRIMARY KEY ("owner_id", "item_id")'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dialects')]
    public function testEveryDialectEmitsTheCompositeKey(object $dialect, string $expected): void
    {
        /** @var \Nandan108\Attrecord\SqlDialect $dialect */
        $sql = $dialect->buildCreateTable(TableSchema::fromClass(CompositeKeyRecord::class));

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

    // ------------------------------------------ CRUD on the whole key, or not at all

    /** An INSERT carries the whole key, because the caller minted all of it. */
    public function testSaveInsertsWithEveryKeyMember(): void
    {
        $session = new CapturingDbSession();
        Record::setConnection(new Connection($session, new MysqlDialect()));

        CompositeKeyRecord::newWith(['owner_id' => 4, 'item_id' => 'aa', 'quantity' => 2])->save();

        $sql = (string) $session->lastSql();
        self::assertStringStartsWith('INSERT INTO `attrecord_composite_probe`', $sql);
        self::assertStringContainsString('`owner_id`', $sql);
        self::assertStringContainsString('`item_id`', $sql, 'the non-leading member is written too');
    }

    /**
     * The UPDATE has to name the whole key. On `WHERE owner_id = ?` alone it would rewrite every
     * row sharing that owner — the wrong-row bug in its most expensive form, since it is a write.
     */
    public function testSaveUpdatesOnTheWholeKey(): void
    {
        $session = new CapturingDbSession();
        Record::setConnection(new Connection($session, new MysqlDialect()));

        // Hydrated rather than constructed: that is what makes it an existing row, so save()
        // takes the UPDATE branch instead of inserting.
        $record = new CompositeKeyRecord();
        $record->hydrateFromRow(['owner_id' => 4, 'item_id' => 'aa', 'quantity' => 2]);
        $record->quantity = 9;
        $record->save();

        $sql = (string) $session->lastSql();
        self::assertStringContainsString('UPDATE `attrecord_composite_probe` SET', $sql);
        self::assertStringContainsString('WHERE `owner_id` = ? AND `item_id` = ?', $sql);
        self::assertStringNotContainsString('SET `owner_id`', $sql, 'identity is not data');
    }

    public function testDeleteNamesTheWholeKey(): void
    {
        $session = new CapturingDbSession();
        Record::setConnection(new Connection($session, new MysqlDialect()));

        CompositeKeyRecord::newWith(['owner_id' => 4, 'item_id' => 'aa', 'quantity' => 1])->delete();

        self::assertSame(
            'DELETE FROM `attrecord_composite_probe` WHERE `owner_id` = ? AND `item_id` = ?',
            (string) $session->lastSql(),
        );
    }

    /**
     * A half-supplied key names a set of rows, so the write is refused rather than run — and the
     * message names the member that is missing, since "incomplete" is useless on a wide key.
     *
     * **The guard detects `null`, which is all it can detect.** A member declared `int $x = 0`
     * has no unset state to find: 0 is a legitimate key value and an omitted assignment produces
     * it, so such a member is indistinguishable from one supplied. Declaring key properties
     * nullable — as an auto-increment `?int $id = null` already is — is what makes the omission
     * visible, and this fixture does that deliberately.
     */
    public function testDeleteRefusesAKeyMissingAMember(): void
    {
        Record::setConnection(new Connection(new CapturingDbSession(), new MysqlDialect()));

        $this->expectException(RecordDeleteException::class);
        $this->expectExceptionMessage('"item_id" has no value');

        NullableMemberPkRecord::newWith(['owner_id' => 4])->delete();
    }

    /**
     * The three-step upsert keys its locking read, its derived table and its join on the whole
     * key. Joining on the first member alone would attach one derived row to every row sharing
     * that member and write the wrong values into each — a set-based version of the wrong-row bug.
     */
    public function testUpsertAllKeysEveryStepOnTheWholeKey(): void
    {
        $session = new CapturingDbSession();
        Record::setConnection(new Connection($session, new MysqlDialect()));

        $existing = new CompositeKeyRecord();
        $existing->hydrateFromRow(['owner_id' => 4, 'item_id' => 'aa', 'quantity' => 1]);
        $existing->quantity = 7;

        (new RecordSet([$existing]))->upsertAll();

        $sql = implode("\n", array_map(
            static fn (array $call): string => \is_string($call['sql'] ?? null) ? $call['sql'] : '',
            $session->allCalls(),
        ));
        self::assertStringContainsString('(`owner_id`, `item_id`) IN ((4, ', $sql, 'the locking read uses a row-value tuple');
        self::assertStringContainsString('ORDER BY `owner_id` ASC, `item_id` ASC', $sql);
        self::assertStringContainsString('`attrecord_composite_probe`.`owner_id` = u.`owner_id` AND `attrecord_composite_probe`.`item_id` = u.`item_id`', $sql, 'joined on the whole key');
        self::assertStringNotContainsString('SET\n    `owner_id`', $sql, 'no key member is ever SET');
    }

    /** The paths that still refuse do so by name. */
    public function testTheRemainingBulkRefusalsNameThemselves(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('upsertAllByUniqueKey()');

        (new RecordSet([new CompositeKeyRecord()]))->upsertAllByUniqueKey('whatever');
    }

    /**
     * The ordering that carries the deadlock guarantee. Ascending by `pk` alone would order on the
     * first member only — a *partial* order, under which two rows sharing an owner could be taken
     * in either sequence. Two orderings of one table is the deadlock LockSet exists to stop, so
     * the whole tuple has to appear in the ORDER BY.
     */
    public function testLockSetOrdersByTheWholeKey(): void
    {
        $session = new CapturingDbSession();
        LockSet::acquire(
            new Connection($session, new MysqlDialect()),
            [CompositeKeyRecord::class => [
                ['owner_id' => 1, 'item_id' => 'a'],
                ['owner_id' => 1, 'item_id' => 'b'],
            ]],
        );

        $sql = (string) $session->lastSql();
        self::assertStringContainsString('ORDER BY `owner_id` ASC, `item_id` ASC', $sql);
        self::assertStringContainsString('(`owner_id`, `item_id`) IN ((?, ?), (?, ?))', $sql, 'one row-value tuple per target row');
        self::assertCount(4, (array) $session->lastParams(), 'every member of every key is bound');
    }

    /**
     * A partial key is refused rather than matched. This is the wrong-row bug the DDL-only
     * refusal originally existed to prevent, and it stays prevented — what changed is that the
     * whole key is now accepted, not that less of it is.
     */
    public function testLockSetRefusesAScalarKey(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('needs the whole key');

        LockSet::acquire(
            new Connection(new CapturingDbSession(), new MysqlDialect()),
            [CompositeKeyRecord::class => [1]],
        );
    }

    /** An ordinary table's lock read is untouched — same predicate, same ordering, same params. */
    public function testASingleColumnKeyStillLocksExactlyAsBefore(): void
    {
        $session = new CapturingDbSession();
        LockSet::acquire(
            new Connection($session, new MysqlDialect()),
            [SingleKeyLockRecord::class => [7, 3]],
        );

        self::assertSame(
            'SELECT * FROM `attrecord_single_probe` WHERE `id` IN (?, ?) ORDER BY `id` ASC FOR UPDATE',
            (string) $session->lastSql(),
        );
    }

    /**
     * The key is ordered by the *schema*, never by the caller's array. Otherwise the emitted
     * predicate and the bound parameters could disagree about which value belongs to which
     * column — a wrong-row read that no assertion on either half alone would catch.
     */
    public function testKeyOrderComesFromTheSchemaNotTheCaller(): void
    {
        $schema = TableSchema::fromClass(CompositeKeyRecord::class);

        self::assertSame(
            ['owner_id' => 1, 'item_id' => 'z'],
            $schema->normalizeKey(['item_id' => 'z', 'owner_id' => 1], 'test()'),
            'reordered to key order regardless of how it was written',
        );
    }

    public function testAKeyMissingAMemberIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('missing its "item_id" member');

        TableSchema::fromClass(CompositeKeyRecord::class)->normalizeKey(['owner_id' => 1], 'test()');
    }

    public function testAKeyCarryingANonMemberIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('not part of the primary key');

        TableSchema::fromClass(CompositeKeyRecord::class)
            ->normalizeKey(['owner_id' => 1, 'item_id' => 'z', 'label' => 'x'], 'test()');
    }

    /** Identity is not data: an UPDATE must never SET a key member. */
    public function testNoKeyMemberIsADataColumn(): void
    {
        $schema = TableSchema::fromClass(CompositeKeyRecord::class);

        self::assertNotContains('owner_id', $schema->dataColumnNames);
        self::assertNotContains('item_id', $schema->dataColumnNames, 'the non-leading member too');
    }

    /** A path that still refuses has to say what to do instead, not merely that the door is shut. */
    public function testTheRemainingRefusalsNameTheKeyAndThePointOfTheFeature(): void
    {
        try {
            (new RecordSet([new CompositeKeyRecord()]))->load('whatever');
            self::fail('expected a SchemaException');
        } catch (SchemaException $e) {
            self::assertStringContainsString('owner_id, item_id', $e->getMessage(), 'names the actual key');
            self::assertStringContainsString('load()', $e->getMessage(), 'names the operation');
        }
    }

    /** Reads are *not* blocked: a SELECT by WHERE needs no primary key. */
    public function testReadBuildersAreNotBlocked(): void
    {
        $session = new CapturingDbSession();
        Record::setConnection(new Connection($session, new MysqlDialect()));

        // where() executes immediately and returns the result set.
        CompositeKeyRecord::where('owner_id', 1);

        self::assertNotSame([], $session->allCalls(), 'a WHERE-based read still runs');
    }
}

// ---------------------------------------------------------------- fixtures

/** @internal a state table keyed on two columns, read and written by raw SQL */
#[Table(name: 'attrecord_composite_probe')]
#[PrimaryKey(columns: ['owner_id', 'item_id'])]
#[LockTier(1)]
final class CompositeKeyRecord extends Record
{
    #[Column(ColumnType::IntUnsigned)]
    public int $owner_id = 0;

    #[Column(ColumnType::Binary, length: 16)]
    public string $item_id = '';

    #[Column(ColumnType::IntUnsigned)]
    public int $quantity = 0;
}

/** @internal key members declared nullable, so an omitted one is detectable rather than defaulted */
#[Table(name: 'attrecord_nullable_member_pk')]
#[PrimaryKey(columns: ['owner_id', 'item_id'])]
final class NullableMemberPkRecord extends Record
{
    #[Column(ColumnType::IntUnsigned)]
    public ?int $owner_id = null;

    #[Column(ColumnType::VarChar, length: 16)]
    public ?string $item_id = null;
}

/** @internal the control: an ordinary key, so "unchanged" is asserted rather than assumed */
#[Table(name: 'attrecord_single_probe')]
#[LockTier(2)]
final class SingleKeyLockRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
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
