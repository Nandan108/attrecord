<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\PrimaryKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * A row identified by two columns, shaped like the cost sidecar the feature was built for: one
 * subject may carry a different cost per area, so `subject_id` alone identifies a *set* of rows.
 *
 * **Both key members are deliberately populated with repeats across the fixture's rows** — several
 * areas per subject and several subjects per area. A fixture whose members are unique on their own
 * converges whether or not the whole key is used, so it cannot tell a correct implementation from
 * one matching on the first member; these cases are the only thing standing between that bug and a
 * green suite.
 *
 * Carries `#[LockTier]` because locking is the path where a partial key is worst: it would take
 * locks on rows the caller never named.
 */
#[Table(name: 'attrecord_composite_costs')]
#[PrimaryKey(columns: ['subject_id', 'area_id'])]
#[LockTier(5)]
final class CompositeKeyCostRecord extends Record
{
    #[Column(ColumnType::IntUnsigned)]
    public ?int $subject_id = null;

    #[Column(ColumnType::IntUnsigned)]
    public ?int $area_id = null;

    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_cost = null;

    #[Column(ColumnType::VarChar, length: 16, nullable: true)]
    public ?string $note = null;
}
