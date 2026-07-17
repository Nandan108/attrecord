<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Caster\EpochCaster;
use Nandan108\Attrecord\Caster\JsonCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Fixture exercising every caster declaration path.
 */
#[Table(name: 'casting_records')]
final class CastingRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** Auto-attached JsonCaster (array-typed Json column, no explicit caster). */
    #[Column(ColumnType::Json, nullable: true)]
    public ?array $meta = null;

    /** Explicit, parameterized caster. */
    #[Column(ColumnType::Json, nullable: true)]
    #[JsonCaster(excludeNullFields: ['note'])]
    public ?array $audit = null;

    /** Epoch caster on an integer column — must not be re-coerced by the integer arm. */
    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    #[EpochCaster]
    public ?\DateTimeImmutable $logged_at = null;

    /** Plain string-typed Json — must remain raw passthrough (BC). */
    #[Column(ColumnType::Json, nullable: true)]
    public ?string $raw_json = null;

    /** JsonCastable value object — auto-cast via jsonSerialize()/fromJson(), no #[Cast]. */
    #[Column(ColumnType::Json, nullable: true)]
    public ?Money $price = null;

    /** Backed enum on an integer column — hydrates to/from the enum via EnumCaster. */
    #[Column(ColumnType::TinyIntUnsigned, nullable: true)]
    #[EnumCaster(SampleStatus::class)]
    public ?SampleStatus $status = null;

    /**
     * String-backed enum on an Enum column with NO explicit `enumValues:` — the schema builder
     * derives the ENUM(...) value list from the caster's cases.
     */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(SampleKind::class)]
    public ?SampleKind $kind = null;
}
