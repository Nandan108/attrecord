<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\JsonCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Invalid: a caster on an autoIncrement column. Schema build must reject it.
 */
#[Table(name: 'bad_autoinc_caster')]
final class BadAutoIncCasterRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    #[JsonCaster]
    public ?array $id = null;
}
