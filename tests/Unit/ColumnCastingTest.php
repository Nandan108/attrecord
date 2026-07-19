<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Caster\DateTimeCaster;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Caster\EpochCaster;
use Nandan108\Attrecord\Caster\JsonCaster;
use Nandan108\Attrecord\ColumnCaster;
use Nandan108\Attrecord\ColumnSerializer;
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\BadAutoIncCasterRecord;
use Nandan108\Attrecord\Tests\Fixtures\BadDoubleCasterRecord;
use Nandan108\Attrecord\Tests\Fixtures\CastingRecord;
use Nandan108\Attrecord\Tests\Fixtures\DiscriminatorRecord;
use Nandan108\Attrecord\Tests\Fixtures\Money;
use Nandan108\Attrecord\Tests\Fixtures\SampleStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** String-backed enum — EnumCaster must round-trip it verbatim (no int coercion). */
enum SampleBasis: string
{
    case Wac = 'wac';
    case Fifo = 'fifo';
}

/** Pure (non-backed) enum — EnumCaster must reject it at construction. */
enum SamplePureEnum
{
    case A;
}

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[CoversClass(JsonCaster::class)]
#[CoversClass(EpochCaster::class)]
#[CoversClass(DateTimeCaster::class)]
#[CoversClass(EnumCaster::class)]
#[CoversClass(ColumnSerializer::class)]
final class ColumnCastingTest extends TestCase
{
    private CapturingDbSession $session;

    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        Record::setConnection(new Connection($this->session, new MysqlDialect()));
        TableSchema::clearCache();
    }

    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    /** @param non-empty-string $phpType */
    private function colDef(ColumnType $type, ?ColumnCaster $caster, string $phpType = 'array'): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'c',
            propertyName: 'c',
            type: $type,
            nullable: true,
            autoIncrement: false,
            trimOnSave: null,
            length: null,
            precision: null,
            scale: null,
            phpType: $phpType,
            caster: $caster,
        );
    }

    #[Test]
    public function jsonCasterRoundTripsThroughColumnSerializer(): void
    {
        $col = $this->colDef(ColumnType::Json, new JsonCaster());

        self::assertSame('{"a":1,"b":["x"]}', ColumnSerializer::toParam(['a' => 1, 'b' => ['x']], $col));
        self::assertSame(['a' => 1, 'b' => ['x']], ColumnSerializer::fromDb('{"a":1,"b":["x"]}', $col));
        // Null short-circuits, caster never invoked.
        self::assertNull(ColumnSerializer::toParam(null, $col));
        self::assertNull(ColumnSerializer::fromDb(null, $col));
    }

    #[Test]
    public function jsonCasterExcludesNullFieldsWhenConfigured(): void
    {
        $col = $this->colDef(ColumnType::Json, new JsonCaster(excludeNullFields: ['note']));

        self::assertSame('{"keep":1}', ColumnSerializer::toParam(['keep' => 1, 'note' => null], $col));
        // Non-null note is retained.
        self::assertSame('{"keep":1,"note":"hi"}', ColumnSerializer::toParam(['keep' => 1, 'note' => 'hi'], $col));
    }

    #[Test]
    public function jsonCasterExcludesAllNullFieldsWhenTrue(): void
    {
        $col = $this->colDef(ColumnType::Json, new JsonCaster(excludeNullFields: true));

        self::assertSame(
            '{"keep":1,"zero":0}',
            ColumnSerializer::toParam(['keep' => 1, 'a' => null, 'zero' => 0, 'b' => null], $col),
        );
    }

    #[Test]
    public function epochCasterIsAuthoritativeOverIntegerArm(): void
    {
        $col = $this->colDef(ColumnType::BigIntUnsigned, new EpochCaster(), phpType: \DateTimeImmutable::class);

        $dt = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));
        // toParam returns the epoch int (NOT a native datetime string), proving the caster wins.
        self::assertSame($dt->getTimestamp(), ColumnSerializer::toParam($dt, $col));

        $back = ColumnSerializer::fromDb((string) $dt->getTimestamp(), $col);
        self::assertInstanceOf(\DateTimeImmutable::class, $back);
        self::assertSame($dt->getTimestamp(), $back->getTimestamp());
    }

    #[Test]
    public function datetimeCasterNormalizesThroughTimezone(): void
    {
        $col = $this->colDef(ColumnType::DateTime, new DateTimeCaster('UTC'), phpType: \DateTimeImmutable::class);

        $dt = new \DateTimeImmutable('2026-01-02 12:00:00', new \DateTimeZone('+02:00'));
        self::assertSame('2026-01-02 10:00:00', ColumnSerializer::toParam($dt, $col));

        $back = ColumnSerializer::fromDb('2026-01-02 10:00:00', $col);
        self::assertInstanceOf(\DateTimeImmutable::class, $back);
        self::assertSame('2026-01-02 10:00:00', $back->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function enumCasterRoundTripsIntBackedEnum(): void
    {
        $col = $this->colDef(ColumnType::TinyIntUnsigned, new EnumCaster(SampleStatus::class), phpType: SampleStatus::class);

        self::assertSame(3, ColumnSerializer::toParam(SampleStatus::Submitted, $col));
        // A driver may return the int column as a numeric string; the caster normalizes to the backing.
        self::assertSame(SampleStatus::Submitted, ColumnSerializer::fromDb('3', $col));
        self::assertSame(SampleStatus::Submitted, ColumnSerializer::fromDb(3, $col));
        // Null short-circuits — the caster is never invoked.
        self::assertNull(ColumnSerializer::toParam(null, $col));
        self::assertNull(ColumnSerializer::fromDb(null, $col));
    }

    #[Test]
    public function enumCasterRoundTripsStringBackedEnum(): void
    {
        $col = $this->colDef(ColumnType::VarChar, new EnumCaster(SampleBasis::class), phpType: SampleBasis::class);

        self::assertSame('wac', ColumnSerializer::toParam(SampleBasis::Wac, $col));
        self::assertSame(SampleBasis::Wac, ColumnSerializer::fromDb('wac', $col));
    }

    #[Test]
    public function enumCasterRejectsNonBackedEnum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument — deliberately passing a non-backed enum to prove the guard. */
        new EnumCaster(SamplePureEnum::class);
    }

    #[Test]
    public function enumCasterDerivesEnumColumnValuesFromCases(): void
    {
        // `kind` is `#[Column(ColumnType::Enum)]` + `#[EnumCaster(SampleKind::class)]` with NO
        // inline `enumValues:` — the schema builder derives the value list from the enum's cases.
        $schema = CastingRecord::schema();

        self::assertSame(['alpha', 'beta'], $schema->columns['kind']->enumValues);
    }

    #[Test]
    public function jsonCasterAutoAttachesOnlyToArrayTypedJsonColumns(): void
    {
        $schema = CastingRecord::schema();

        self::assertInstanceOf(JsonCaster::class, $schema->columns['meta']->caster);
        // Explicit parameterized caster wins over auto-attach.
        $audit = $schema->columns['audit']->caster;
        self::assertInstanceOf(JsonCaster::class, $audit);
        self::assertSame(['note'], $audit->excludeNullFields);
        // String-typed Json keeps raw passthrough — BC.
        self::assertNull($schema->columns['raw_json']->caster);
        // Epoch caster on an integer column.
        self::assertInstanceOf(EpochCaster::class, $schema->columns['logged_at']->caster);
        // JsonCastable VO column auto-attaches a JsonCaster.
        self::assertInstanceOf(JsonCaster::class, $schema->columns['price']->caster);
    }

    #[Test]
    public function jsonCastableValueObjectRoundTrips(): void
    {
        $col = $this->colDef(ColumnType::Json, new JsonCaster(), phpType: Money::class);

        // Encode: VO serialized via its own jsonSerialize().
        self::assertSame('{"amount":1299,"currency":"EUR"}', ColumnSerializer::toParam(new Money(1299, 'EUR'), $col));

        // Decode: reconstructed via fromJson().
        $back = ColumnSerializer::fromDb('{"amount":1299,"currency":"EUR"}', $col);
        self::assertInstanceOf(Money::class, $back);
        self::assertSame(1299, $back->amount);
        self::assertSame('EUR', $back->currency);
    }

    #[Test]
    public function jsonCastableValueObjectStaysCleanAfterHydrate(): void
    {
        $rec = CastingRecord::hydrateFromArray([
            'id'    => 1,
            'price' => '{"amount": 500, "currency": "USD"}',
        ]);

        self::assertInstanceOf(Money::class, $rec->price);
        self::assertSame(500, $rec->price->amount);
        // Canonical re-encoded snapshot ⇒ no false-dirty despite the spaced raw payload.
        self::assertFalse($rec->isDirty('price'));

        $rec->price = new Money(500, 'EUR');
        self::assertTrue($rec->isDirty('price'));
    }

    #[Test]
    public function casterReadsSiblingDiscriminatorOnHydrate(): void
    {
        $rec = DiscriminatorRecord::hydrateFromArray([
            'id'      => 7,
            'kind'    => 'shipment',
            'payload' => '{"tracking":"ABC"}',
        ]);

        self::assertSame(['kind' => 'shipment', 'data' => ['tracking' => 'ABC']], $rec->payload);
    }

    #[Test]
    public function castedColumnIsCleanAfterHydrateAndDirtyAfterChange(): void
    {
        // Raw DB form with whitespace the way native JSON normalization might differ.
        $rec = CastingRecord::hydrateFromArray([
            'id'        => 1,
            'meta'      => '{"a": 1}',
            'audit'     => null,
            'logged_at' => '1767322445',
            'raw_json'  => null,
        ]);

        self::assertSame(['a' => 1], $rec->meta);
        self::assertInstanceOf(\DateTimeImmutable::class, $rec->logged_at);
        // Re-encoded snapshot means a freshly loaded, untouched record is clean despite
        // the original raw value's spacing.
        self::assertFalse($rec->isDirty('meta'));
        self::assertFalse($rec->isDirty('logged_at'));

        $rec->meta = ['a' => 2];
        self::assertTrue($rec->isDirty('meta'));
    }

    #[Test]
    public function singleSaveEmitsCastScalar(): void
    {
        $rec = new CastingRecord();
        $rec->meta = ['x' => true];
        $rec->save();

        $insert = $this->session->allCalls()[0];
        self::assertStringContainsString('INSERT INTO', $insert['sql']);
        self::assertContains('{"x":true}', $insert['params']);
    }

    #[Test]
    public function bulkUpsertAllEmitsCastLiteral(): void
    {
        $set = new \Nandan108\Attrecord\RecordSet([
            CastingRecord::newWith(['meta' => ['n' => 1]]),
            CastingRecord::newWith(['meta' => ['n' => 2]]),
        ]);
        $set->upsertAll();

        // String literals escape the JSON quotes in MySQL dialect output.
        $insert = $this->session->allCalls()[0]['sql'];
        self::assertStringContainsString('{\"n\":1}', $insert);
        self::assertStringContainsString('{\"n\":2}', $insert);
    }

    #[Test]
    public function casterOnAutoincrementColumnIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        BadAutoIncCasterRecord::schema();
    }

    #[Test]
    public function multipleCasterAttributesAreRejected(): void
    {
        $this->expectException(SchemaException::class);
        BadDoubleCasterRecord::schema();
    }
}
