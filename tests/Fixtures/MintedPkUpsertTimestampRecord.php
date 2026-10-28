<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Caller-minted (non-autoincrement) PK + auto-managed timestamps — the shape of a row the
 * application identifies before it knows whether the database already holds it, upserted through
 * {@see \Nandan108\Attrecord\RecordSet::upsertAll()}.
 *
 * The timestamp columns are deliberately **NOT NULL** with no default: an unstamped `#[CreatedAt]`
 * is then a hard INSERT failure on every backend rather than a silently-wrong value (a host that
 * relaxes strict mode would store a zero date instead).
 */
#[Table(name: 'attrecord_minted_ts_upsert')]
final class MintedPkUpsertTimestampRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    #[UpdatedAt]
    public ?\DateTimeImmutable $updated_at = null;
}
