<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Mutable;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\Record;

/**
 * An interned row that can still be **flagged**: the facts are fixed, but whether they are still
 * valid is metadata laid over them.
 *
 * The distinction the fixture exists to pin: `name` is part of what the row *is* — on a
 * content-addressed table it would be inside the digest — while `invalid_at` and `invalid_reason`
 * are facts *about* those facts, true for everything that ever stated them, and outside it.
 */
#[Table(name: 'attrecord_immutable_flaggable')]
final class ImmutableFlaggableRecord extends Record implements Immutable
{
    #[Column(ColumnType::BigIntUnsigned)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, nullable: true)]
    #[Mutable]
    public ?\DateTimeImmutable $invalid_at = null;

    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    #[Mutable]
    public ?string $invalid_reason = null;
}
