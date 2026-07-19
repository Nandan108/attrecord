<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Append-only (write-once) fixture: application-minted PK + #[CreatedAt], implements {@see AppendOnly}.
 *
 * Exercises the append-only enforcement — inserts (insertAll / new-record save) and finders must
 * work; every update/delete path must throw {@see \Nandan108\Attrecord\Exception\AppendOnlyViolationException}.
 */
#[Table(name: 'attrecord_append_only_ledger')]
#[UniqueKey('uk_name', columns: ['name'])]
final class AppendOnlyLedgerRecord extends Record implements AppendOnly
{
    #[Column(ColumnType::BigIntUnsigned)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, nullable: true)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;
}
