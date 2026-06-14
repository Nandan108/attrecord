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
 * Exercises class-level #[ForeignKey]: a Record-less target with default target column +
 * SET NULL, a Record-less target with an explicit target column + CASCADE, and a
 * Record-class target (table name + PK derived from UserRecord).
 */
#[Table(name: 'attrecord_ledger')]
#[ForeignKey(column: 'slot_id', references: 'attrecord_slots', onDelete: ForeignKeyAction::SetNull)]
#[ForeignKey(column: 'owner_id', references: 'attrecord_owners', referencesColumn: 'owner_pk', onDelete: ForeignKeyAction::Cascade)]
#[ForeignKey(column: 'user_id', references: UserRecord::class, onDelete: ForeignKeyAction::Restrict)]
final class DdlForeignKeyRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $slot_id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $owner_id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $user_id = null;
}
