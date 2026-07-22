<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\Version;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** Optimistic-locking subject: an integer `#[Version]` column seeded on INSERT and bumped on UPDATE. */
#[Table(name: 'attrecord_versioned')]
final class VersionedRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    public string $name = '';

    #[Column(ColumnType::IntUnsigned)]
    #[Version]
    public ?int $version = null;
}
