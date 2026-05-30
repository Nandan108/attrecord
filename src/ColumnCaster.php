<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Bidirectional converter between a rich PHP value (value object, typed array,
 * polymorphic payload) and the DB scalar stored in a single column.
 *
 * Casters are declared as #[Cast]-family attributes on the column property — the
 * attribute instance IS the caster (see {@see Attribute\Cast}).
 *
 * Implementations MUST be stateless and side-effect free: one instance is created per
 * property at schema-build time and reused across all rows of that Record class.
 * Per-instance config is fine and immutable (readonly constructor args); per-row
 * mutable state is not.
 *
 * The framework handles null on both sides — a caster is never invoked with a null
 * raw value (read) or a null property value (write); those short-circuit to null.
 *
 * @api
 */
interface ColumnCaster
{
    /**
     * Raw DB scalar (as returned by DbSession::fetchAll) → rich PHP value.
     *
     * $row is the full raw row currently being hydrated, so a caster can read a sibling
     * discriminator column (e.g. $row['event_type']) to choose the target type.
     *
     * @param scalar                     $raw
     * @param array<string, scalar|null> $row
     */
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed;

    /**
     * Rich PHP value → DB scalar for parameter binding and literal rendering.
     */
    public function toDb(mixed $value, ColumnDefinition $col): int | float | string | null;
}
