<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** #[ForeignKey] naming a column that isn't declared — must be rejected at schema build. */
#[Table(name: 'attrecord_bad_fk')]
#[ForeignKey(column: 'nonexistent', references: 'attrecord_slots')]
final class DdlBadForeignKeyRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}
