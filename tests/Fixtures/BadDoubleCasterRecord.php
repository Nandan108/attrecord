<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EpochCaster;
use Nandan108\Attrecord\Caster\JsonCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Invalid: two caster attributes on one property. Schema build must reject it.
 */
#[Table(name: 'bad_double_caster')]
final class BadDoubleCasterRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Json, nullable: true)]
    #[JsonCaster]
    #[EpochCaster]
    public ?array $x = null;
}
