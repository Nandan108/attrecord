<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Caster;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Opt-in temporal caster: maps a DateTimeImmutable to/from unix-seconds, for a column
 * declared with an integer ColumnType. Never auto-attached.
 *
 * Usage: #[EpochCaster] on an `int`/`?int`-backed integer column holding a timestamp.
 *
 * Not `final`: intended to be extended (e.g. millisecond precision).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class EpochCaster extends Cast
{
    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.(int) $raw);
    }

    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): int
    {
        \assert($value instanceof \DateTimeInterface);

        return $value->getTimestamp();
    }
}
