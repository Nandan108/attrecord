<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Caster;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\JsonCastable;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Casts between a PHP array (or any json-encodable value) and a JSON string column.
 *
 * Auto-attached by the schema builder to a `ColumnType::Json` column whose property is
 * declared `array`/`?array` when no other caster attribute is present; may also be used
 * explicitly with configuration.
 *
 * Not `final`: intended to be extended for custom encoding needs.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class JsonCaster extends Cast
{
    /**
     * @param array<int, string>|bool $excludeNullFields controls which null-valued top-level
     *                                                   keys are dropped from the encoded object:
     *                                                   `false` (default) keeps them all,
     *                                                   `true` drops every null-valued key,
     *                                                   a list of names drops only those when null
     */
    public function __construct(public readonly array | bool $excludeNullFields = false)
    {
    }

    /** @throws \JsonException */
    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed
    {
        /** @psalm-var mixed $decoded */
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        // When the property is typed as a JsonCastable value object, rebuild it from
        // the decoded payload; otherwise return the plain decoded value (array/scalar).
        $type = $col->phpType;
        if (\is_array($decoded) && null !== $type && is_a($type, JsonCastable::class, true)) {
            return $type::fromJson($decoded);
        }

        return $decoded;
    }

    /** @throws \JsonException */
    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): string
    {
        $exclude = $this->excludeNullFields;
        if (\is_array($value) && false !== $exclude) {
            if (true === $exclude) {
                $value = array_filter($value, static fn (mixed $v): bool => null !== $v);
            } else {
                foreach ($exclude as $key) {
                    if (\array_key_exists($key, $value) && null === $value[$key]) {
                        unset($value[$key]);
                    }
                }
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
