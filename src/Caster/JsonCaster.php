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
     * @param bool                    $sortKeys          order object keys before encoding, recursively,
     *                                                   using the same rule MySQL's binary `JSON` and
     *                                                   PostgreSQL's `JSONB` apply internally — key
     *                                                   length ascending, then bytewise. Off by default.
     *                                                   See {@see self::withSortedKeys()} for what this
     *                                                   is for and what it does not promise.
     */
    public function __construct(
        public readonly array | bool $excludeNullFields = false,
        public readonly bool $sortKeys = false,
    ) {
    }

    /**
     * Recursively order object keys the way the normalizing engines do.
     *
     * **Why.** `ColumnType::Json` maps to engine types that disagree about key order: MySQL
     * (binary `JSON`) and PostgreSQL (`JSONB`) normalize it, MariaDB (`LONGTEXT`) and SQLite
     * (`TEXT`) store the bytes verbatim. MySQL and PG also *compare* JSON semantically, so
     * `GROUP BY` / `DISTINCT` / a unique index over such a column already behaves correctly
     * there. On MariaDB and SQLite it does not — the same logical payload written with keys in
     * two different orders is two different strings, hence two groups. Sorting on write is what
     * makes those backends agree with themselves, and with the other two.
     *
     * **The ordering is deliberately not `ksort()`.** The engines sort by key *length* first and
     * only then bytewise, so `{"bb":1,"aa":2,"c":3}` normalizes to `{"c":3,"aa":2,"bb":1}` — a
     * lexicographic sort would make MariaDB disagree with the very engines it is imitating.
     *
     * Applied unconditionally, including on the engines that would have normalized anyway: the
     * result is identical either way, and a caster whose output depended on the ambient dialect
     * would stop being the pure, stateless value mapping the rest of the contract assumes.
     *
     * **What this does not promise.** Key order is one input to byte-equality, not all of it —
     * encode flags, float formatting and duplicate keys matter too. The name is narrow on
     * purpose. It is also **not retroactive**: rows written before it was enabled keep their
     * original order and go on forming their own groups until rewritten.
     *
     * Lists are recursed into but never reordered — only objects are sorted. `stdClass` stays
     * `stdClass`, so an empty object keeps encoding as `{}` rather than collapsing to `[]`.
     */
    private static function withSortedKeys(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            /** @psalm-var mixed $value */
            $value = $value->jsonSerialize();
        }

        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            /** @psalm-var mixed $v */
            foreach ($props as $k => $v) {
                /** @psalm-suppress MixedAssignment */
                $props[$k] = self::withSortedKeys($v);
            }
            uksort($props, self::keyOrder(...));

            return (object) $props;
        }

        if (!\is_array($value)) {
            return $value;
        }

        /** @psalm-var mixed $v */
        foreach ($value as $k => $v) {
            /** @psalm-suppress MixedAssignment */
            $value[$k] = self::withSortedKeys($v);
        }
        // A JSON array carries meaning in its order; only objects get sorted.
        if (!array_is_list($value)) {
            uksort($value, self::keyOrder(...));
        }

        return $value;
    }

    /** Key length ascending, then bytewise — the rule MySQL's `JSON` and PostgreSQL's `JSONB` use. */
    private static function keyOrder(int | string $a, int | string $b): int
    {
        $a = (string) $a;
        $b = (string) $b;

        return \strlen($a) <=> \strlen($b) ?: strcmp($a, $b);
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

        if ($this->sortKeys) {
            /** @psalm-var mixed $value */
            $value = self::withSortedKeys($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
