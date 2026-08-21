<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * The referenced side of the inbound-foreign-key cases: a content-addressed, append-only party
 * table, whose rows accumulate and whose interesting question is "does anything still point here?".
 *
 * Keyed on a natural string rather than an auto-increment, because a referenced column that is not
 * the primary key is precisely where an inbound reader can be got wrong.
 */
#[Table(name: 'attrecord_ref_parties', primaryKey: 'content_hash')]
final class RefPartyRecord extends Record
{
    #[Column(ColumnType::VarChar, length: 64)]
    public string $content_hash = '';

    #[Column(ColumnType::VarChar, length: 128)]
    public string $name = '';
}
