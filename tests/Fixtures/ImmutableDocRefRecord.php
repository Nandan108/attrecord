<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/** A document pointing at an interned {@see ImmutableDocRecord} — the thing that makes one non-reapable. */
#[Table(name: 'attrecord_immutable_doc_ref')]
#[ForeignKey(column: 'doc_id', references: ImmutableDocRecord::class, onDelete: ForeignKeyAction::Restrict)]
final class ImmutableDocRefRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $doc_id = 0;
}
