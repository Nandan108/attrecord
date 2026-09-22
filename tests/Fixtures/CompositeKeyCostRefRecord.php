<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/**
 * A child of {@see CompositeKeyCostRecord}, referencing it by the **whole** two-column key.
 *
 * The FK is what the fixture exists for, and one row proves it: `(1, 999)` names a subject that
 * exists and an area that does not. A constraint over both columns rejects it; the single-column
 * constraint attrecord emitted before v0.23 — `REFERENCES … (subject_id)`, the key's first member —
 * accepts it happily, because subject 1 is there. So an engine that takes the insert is an engine
 * running the wrong constraint.
 */
#[Table(name: 'attrecord_composite_cost_refs')]
#[ForeignKey(
    column: ['cost_subject_id', 'cost_area_id'],
    references: CompositeKeyCostRecord::class,
    onDelete: ForeignKeyAction::Cascade,
)]
final class CompositeKeyCostRefRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    public ?int $cost_subject_id = null;

    #[Column(ColumnType::IntUnsigned)]
    public ?int $cost_area_id = null;

    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $label = null;
}
