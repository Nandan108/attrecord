<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Caster;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Opt-in temporal caster: like the native DateTime path but normalizes through a
 * configurable storage timezone. Never auto-attached — plain DateTime/Date columns
 * use the native serializer by default.
 *
 * Usage: #[DateTimeCaster('Europe/Zurich')]
 *
 * Not `final`: intended to be extended (e.g. a different storage format).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class DateTimeCaster extends Cast
{
    /** @param non-empty-string $timezone */
    public function __construct(public readonly string $timezone = 'UTC')
    {
    }

    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) $raw, new \DateTimeZone($this->timezone));
    }

    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): string
    {
        \assert($value instanceof \DateTimeInterface);

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone($this->timezone))
            ->format('Y-m-d H:i:s');
    }
}
