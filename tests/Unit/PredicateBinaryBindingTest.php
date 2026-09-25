<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\WhereClause;
use PHPUnit\Framework\TestCase;

/**
 * A predicate compared against a binary column binds its bytes as a {@see BinaryParam}.
 *
 * The write path has always done this — `pkParams()` wraps a binary key member — while predicates
 * did not, so the same bytes were marked when they were a key and unmarked when they were a
 * condition. PostgreSQL rejects the unmarked form outright ("Character not in repertoire"), and a
 * MySQL-shaped translator over another engine quietly matches nothing.
 *
 * **The wrap is narrow on purpose, and most of these cases are about what it must NOT touch.**
 * Routing predicate values through the column serializer would re-apply casters, which are written
 * for the PHP-typed values of the write path: a `JsonCaster` re-encodes `'active'` to `'"active"'`
 * and matches nothing, an `EnumCaster` asserts on a type this signature cannot carry. So a caster on
 * the column disqualifies the value, and everything non-binary passes through byte-identical.
 */
final class PredicateBinaryBindingTest extends TestCase
{
    private const BYTES = "\x01\x92\xf8\xa4\xb3\xc2\x7d\x4e\x8f\x1a\x2b\x3c\x4d\x5e\x6f\x70";

    private function schema(): TableSchema
    {
        return TableSchema::fromClass(PredicateBindingRecord::class);
    }

    /** @return list<mixed> */
    private function bind(WhereClause $where, bool $bindBinaryAsLob = true): array
    {
        return $where->params($this->schema(), $bindBinaryAsLob);
    }

    public function testABinaryColumnIsWrapped(): void
    {
        $params = $this->bind(WhereClause::where('owner_id', self::BYTES));

        self::assertCount(1, $params);
        self::assertInstanceOf(BinaryParam::class, $params[0]);
        self::assertSame(self::BYTES, $params[0]->bytes, 'the bytes survive unchanged');
    }

    /** MySQL binds raw bytes through an ordinary string parameter, so it must stay a string there. */
    public function testNothingIsWrappedWhenTheDialectDoesNotNeedIt(): void
    {
        $params = $this->bind(WhereClause::where('owner_id', self::BYTES), bindBinaryAsLob: false);

        self::assertSame([self::BYTES], $params);
    }

    /** Called without a schema — the pre-0.24 signature — behaviour is unchanged. */
    public function testTheBareCallIsUntouched(): void
    {
        self::assertSame([self::BYTES], WhereClause::where('owner_id', self::BYTES)->params());
    }

    /**
     * The caster guard. A cast column is left alone, because the value in a predicate is already
     * scalar and the caster expects the write path's PHP type — applying it would transform a value
     * the caller spelled correctly.
     */
    public function testACastColumnIsNotTouched(): void
    {
        $params = $this->bind(WhereClause::where('payload', 'active'));

        self::assertSame(['active'], $params, 'not wrapped, and above all not re-encoded');
    }

    public function testANonBinaryColumnIsUntouched(): void
    {
        self::assertSame([7], $this->bind(WhereClause::where('qty', 7)));
    }

    /** A joined table's column, or an alias: unknown here, so it passes through rather than throwing. */
    public function testAnUnknownColumnPassesThrough(): void
    {
        self::assertSame([self::BYTES], $this->bind(WhereClause::where('other_table_ref', self::BYTES)));
    }

    /** Raw SQL carries no column association, so its parameters cannot be marked — by design. */
    public function testRawPredicateParametersAreNeverWrapped(): void
    {
        $where = WhereClause::whereRaw(new RawSql('owner_id = ?', [self::BYTES]));

        self::assertSame([self::BYTES], $this->bind($where), 'bind a BinaryParam explicitly here');
    }

    public function testEveryValueOfAnInListIsWrapped(): void
    {
        $other = str_repeat("\xff", 16);
        $params = $this->bind(WhereClause::whereIn('owner_id', [self::BYTES, $other]));

        self::assertCount(2, $params);
        self::assertInstanceOf(BinaryParam::class, $params[0]);
        self::assertInstanceOf(BinaryParam::class, $params[1]);
        self::assertSame($other, $params[1]->bytes);
    }

    /** Both bounds of a range share the column, so both are marked. */
    public function testBothBetweenBoundsAreWrapped(): void
    {
        $high = str_repeat("\xff", 16);
        $params = $this->bind(WhereClause::whereBetween('owner_id', self::BYTES, $high));

        self::assertCount(2, $params);
        self::assertInstanceOf(BinaryParam::class, $params[0]);
        self::assertInstanceOf(BinaryParam::class, $params[1]);
    }

    /**
     * The tuple form, which is the composite-key path — `(owner_id, qty) IN ((…), …)`.
     *
     * The case worth having: each row's values pair **positionally** with the column list, so the
     * binary member is marked and the integer member beside it is not. An implementation that
     * handled only the simple comparison would leave exactly this broken, and a consumer keying a
     * table on a pair of columns meets it immediately.
     */
    public function testTupleRowsPairPositionallyWithTheirColumns(): void
    {
        $other = str_repeat("\xaa", 16);
        $params = $this->bind(WhereClause::whereInTuples(
            ['owner_id', 'qty'],
            [[self::BYTES, 1], [$other, 2]],
        ));

        self::assertCount(4, $params);
        self::assertInstanceOf(BinaryParam::class, $params[0], 'row 0, binary member');
        self::assertSame(1, $params[1], 'row 0, integer member untouched');
        self::assertInstanceOf(BinaryParam::class, $params[2], 'row 1, binary member');
        self::assertSame(2, $params[3], 'row 1, integer member untouched');
    }

    /** The bool normalization this method already did is unaffected by the new arguments. */
    public function testBoolsAreStillNormalizedToInts(): void
    {
        self::assertSame([1, 0], $this->bind(
            WhereClause::where('flag', true)->andWhere(WhereClause::where('flag', false)),
        ));
    }

    /** Nested compound predicates keep positional order, which is what pairs values to placeholders. */
    public function testOrderSurvivesNesting(): void
    {
        $where = WhereClause::where('qty', 1)
            ->andWhere(WhereClause::where('owner_id', self::BYTES))
            ->andWhere(WhereClause::where('qty', 2));

        $params = $this->bind($where);

        self::assertSame(1, $params[0]);
        self::assertInstanceOf(BinaryParam::class, $params[1]);
        self::assertSame(2, $params[2]);
    }
}

/** @internal a caster that would visibly corrupt a predicate value if it were ever applied */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EchoingCast extends Cast
{
    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed
    {
        return $raw;
    }

    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): string
    {
        return 'CASTED:'.(string) $value;
    }
}

/** @internal */
#[Table(name: 'attrecord_predicate_binding')]
final class PredicateBindingRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Binary, length: 16)]
    public ?string $owner_id = null;

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    #[EchoingCast]
    public ?string $payload = null;

    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $qty = 0;

    #[Column(ColumnType::Bool, default: false)]
    public bool $flag = false;
}
