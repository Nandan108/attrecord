<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * A binary id in a **non-key** column — the shape {@see BinaryPkRecord} does not cover.
 *
 * Every existing binary case addresses rows by their primary key, which has always bound through
 * `Record::pkParams()` and so has always been marked. A binary value reaching the database as a
 * *condition* rather than as a key took a different path and was not, which is the asymmetry
 * v0.24.0 closed. This fixture is deliberately keyed on an ordinary auto-increment id so the only
 * binary value in play is one a predicate has to carry.
 *
 * Mirrors a consumer's FK pattern: rows belonging to a parent identified by a 16-byte UUID, looked
 * up by that parent.
 */
#[Table(name: 'attrecord_binary_ref')]
final class BinaryRefRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** 16 raw bytes, indexed and queried — never this table's key. */
    #[Column(ColumnType::Binary, length: 16)]
    #[Index('idx_binary_ref_owner')]
    public ?string $owner_id = null;

    #[Column(ColumnType::VarChar, length: 64, default: '')]
    public string $label = '';

    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $qty = 0;
}
