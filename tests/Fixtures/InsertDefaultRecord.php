<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Exercises the "let the DB default fire" insert rule: a NOT-NULL column left null on INSERT is
 * omitted from the statement so its declared default takes effect, instead of emitting an explicit
 * NULL that would violate the NOT-NULL constraint. A nullable-with-default column is the control —
 * there a null value means "store NULL", so the column must still be written.
 */
#[Table(name: 'attrecord_insert_default')]
final class InsertDefaultRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** NOT NULL + literal default — leaving it null must let 'pending' fire, not INSERT NULL. */
    #[Column(ColumnType::VarChar, length: 20, default: 'pending')]
    public ?string $status = null;

    /** NOT NULL + default expression — same rule via CURRENT_TIMESTAMP. */
    #[Column(ColumnType::DateTime, defaultExpr: 'CURRENT_TIMESTAMP')]
    public ?\DateTimeImmutable $created_at = null;

    /** Nullable + default — a null here means store NULL; the column must still be written. */
    #[Column(ColumnType::VarChar, length: 20, nullable: true, default: 'fallback')]
    public ?string $note = null;
}
