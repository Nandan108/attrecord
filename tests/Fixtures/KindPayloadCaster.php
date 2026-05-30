<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Discriminator-aware caster: decodes the payload and tags it with the sibling
 * `kind` column read from the raw row.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class KindPayloadCaster extends Cast
{
    /** @throws \JsonException */
    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed
    {
        return [
            'kind' => $row['kind'] ?? null,
            'data' => json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /** @throws \JsonException */
    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): string
    {
        \assert(\is_array($value));

        return json_encode($value['data'] ?? $value, JSON_THROW_ON_ERROR);
    }
}
