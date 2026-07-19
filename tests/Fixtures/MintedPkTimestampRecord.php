<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * App-minted (non-autoincrement) PK plus #[CreatedAt] — the append-only-ledger shape insertAll()
 * targets. Regression guard: insertAll() must stamp created_at even when the PK is non-null.
 */
#[Table(name: 'attrecord_minted_ts')]
final class MintedPkTimestampRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, nullable: true)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;
}
