<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Auto-increment PK + a unique business key + auto-managed timestamps — for proving that the
 * upsert-by-unique-key paths honour `#[CreatedAt]`/`#[UpdatedAt]`.
 *
 * The timestamp columns are deliberately **NOT NULL**: that is what turns "the attribute is
 * ignored" from a silently-wrong value into a hard write failure, which is how the gap first
 * surfaced downstream.
 */
// The key name is table-qualified because PostgreSQL scopes index names per *schema*, not per
// table — a bare `uniq_code` would collide with UpsertByUniqueKeyRecord's in the same test run.
#[Table(name: 'attrecord_ts_upsert')]
#[UniqueKey('uniq_ts_code', columns: ['code'])]
final class TimestampedUpsertRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    #[UpdatedAt]
    public ?\DateTimeImmutable $updated_at = null;

    /** Test convenience builder, mirroring {@see UpsertByUniqueKeyRecord::withCode()}. */
    public function withCode(string $code, string $name): static
    {
        $this->code = $code;
        $this->name = $name;

        return $this;
    }
}
