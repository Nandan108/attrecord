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
 * The referencing side: **two** foreign keys into the same parent table and the same parent column,
 * which is the shape that makes an inbound reader worth having — the answer to "what points at this
 * party" is two columns of one table, and a UNION that forgot to dedupe would double-count a party
 * used as both buyer and consignee.
 */
#[Table(name: 'attrecord_ref_documents')]
#[ForeignKey(column: 'buyer_party_id', references: RefPartyRecord::class, onDelete: ForeignKeyAction::Restrict)]
#[ForeignKey(column: 'ship_to_party_id', references: RefPartyRecord::class, onDelete: ForeignKeyAction::SetNull)]
final class RefDocumentRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $buyer_party_id = null;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $ship_to_party_id = null;
}
