<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\PrimaryKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Composite PK + auto-managed timestamps — a membership-style row the caller re-asserts by building
 * a fresh object each time, so a complete key says nothing about whether the row is already stored.
 *
 * Timestamps are **NOT NULL**, as on {@see MintedPkUpsertTimestampRecord}.
 */
#[Table(name: 'attrecord_composite_ts')]
#[PrimaryKey(columns: ['owner_id', 'slot_id'])]
final class CompositePkTimestampRecord extends Record
{
    #[Column(ColumnType::IntUnsigned)]
    public ?int $owner_id = null;

    #[Column(ColumnType::IntUnsigned)]
    public ?int $slot_id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    #[UpdatedAt]
    public ?\DateTimeImmutable $updated_at = null;
}
