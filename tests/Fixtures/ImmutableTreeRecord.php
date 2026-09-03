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
 * A **self-referencing** table, which is the case that breaks a naively-written
 * {@see Record::deleteUnreferenced()}.
 *
 * With the inner table unaliased, `NOT EXISTS (SELECT 1 FROM t WHERE t.parent_id = t.id)` has its
 * inner `t` shadow the outer one, so the correlation asks whether a row is *its own* parent — true
 * of nobody. The predicate then matches every candidate and the check silently checks nothing.
 */
#[Table(name: 'attrecord_immutable_tree')]
#[ForeignKey(column: 'parent_id', references: 'attrecord_immutable_tree', referencesColumn: 'id', onDelete: ForeignKeyAction::Restrict)]
final class ImmutableTreeRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $parent_id = null;
}
