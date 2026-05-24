<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Fixture for testing BINARY(16) (non-autoincrement) primary key support.
 *
 * Mirrors InvFlux's UUIDv7-as-PK pattern: the application generates the
 * raw 16-byte binary ID and assigns it before save(); attrecord must
 * persist it as-is and not try to backfill from lastInsertId().
 */
#[Table(name: 'attrecord_binary_pk')]
final class BinaryPkRecord extends Record
{
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';
}
