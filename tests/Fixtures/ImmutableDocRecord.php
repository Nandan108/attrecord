<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\Record;

/**
 * Content-addressed fixture: the PK is a digest **of the row's own fields**, so the row is interned
 * and shared by everything stating the same facts — the case {@see Immutable} exists for.
 *
 * Editing it would be incoherent (it would break the key that identifies it, silently, for every
 * other holder), but *deleting* an unreferenced one loses nothing: re-interning the same facts
 * recomputes the same key. So every update path must throw and every delete path must work —
 * the one asymmetry that distinguishes this contract from {@see \Nandan108\Attrecord\AppendOnly}.
 */
#[Table(name: 'attrecord_immutable_doc')]
#[UniqueKey('uk_doc_name', columns: ['name'])]
final class ImmutableDocRecord extends Record implements Immutable
{
    #[Column(ColumnType::BigIntUnsigned)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, nullable: true)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;
}
