<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Check;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Exercises class-level #[Check]: two table-level CHECK constraints of the shape that motivates
 * the feature — a conditional legality rule ("only a unit may be tracked") and a conditional
 * NOT NULL ("a batch must name its parent"), neither of which a column can express on its own.
 *
 * Deliberately portable SQL: this fixture is created for real on all three backends.
 */
#[Table(name: 'attrecord_checked_subjects')]
#[Check('tracking_unit_only', "kind = 'unit' OR tracking = 'none'")]
#[Check('batch_has_parent', "kind <> 'batch' OR parent_id IS NOT NULL")]
final class DdlCheckRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 16)]
    public string $kind = 'unit';

    #[Column(ColumnType::VarChar, length: 16)]
    public string $tracking = 'none';

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $parent_id = null;
}
