<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** #[ForeignKey] whose `references` is a class but not a Record — must be rejected at resolution. */
#[Table(name: 'attrecord_non_record_fk')]
#[ForeignKey(column: 'thing_id', references: \stdClass::class)]
final class DdlNonRecordForeignKeyRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $thing_id = null;
}
