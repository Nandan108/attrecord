<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Caster;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * MySQL-native flag-set caster: maps a PHP **set of enum members** (`list<E>`) to/from a
 * `ColumnType::Set` column — a native MySQL/MariaDB `SET('a','b',…)` storing a subset of named
 * members as a comma-joined string.
 *
 * This is the **self-documenting, MySQL-only** counterpart to {@see BitmaskCaster} (which is portable
 * across every backend by storing an integer bitmask): a `ColumnType::Set` column already throws at
 * schema-build on PostgreSQL/SQLite, so a Record using this caster is MySQL-family by construction —
 * reach for {@see BitmaskCaster} when you need portability.
 *
 * The enum must be **string-backed**; each case's value is a `SET` member (which therefore may not
 * contain a comma). Declare the caster on a `Set`-typed, `array`-typed property and omit
 * `enumValues:` — the `SET(...)` member list is derived from the enum's cases:
 *
 *   #[Column(ColumnType::Set)]
 *   #[SetCaster(AccessRight::class)]
 *   public array $rights = [];          // e.g. [AccessRight::Read, AccessRight::Write]
 *
 * On write the members are joined in **declaration order** (canonical, dedup'd); on read the stored
 * string is split back into members, again in declaration order — so dirty-tracking is order- and
 * duplicate-independent. The framework short-circuits null on both sides (see
 * {@see \Nandan108\Attrecord\ColumnCaster}); the empty set is the empty string.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class SetCaster extends Cast
{
    /**
     * member string => case, in declaration order.
     *
     * @var array<string, \BackedEnum>
     */
    private readonly array $casesByValue;

    /**
     * member string => declaration ordinal, for canonical ordering on write/read.
     *
     * @var array<string, int>
     */
    private readonly array $ordinalByValue;

    /** @param class-string<\BackedEnum> $enum a string-backed enum whose case values are the SET members */
    public function __construct(private readonly string $enum)
    {
        $backing = (new \ReflectionEnum($enum))->getBackingType();
        if (!$backing instanceof \ReflectionNamedType || 'string' !== $backing->getName()) {
            throw new \InvalidArgumentException(sprintf(
                'SetCaster requires a string-backed enum; %s is not string-backed.',
                $enum,
            ));
        }

        $casesByValue = [];
        $ordinalByValue = [];
        $ordinal = 0;
        foreach ($enum::cases() as $case) {
            /** @var string $member */
            $member = $case->value;
            if (str_contains($member, ',')) {
                throw new \InvalidArgumentException(sprintf(
                    'SetCaster: %s member "%s" contains a comma, which a MySQL SET cannot store.',
                    $enum,
                    $member,
                ));
            }
            $casesByValue[$member] = $case;
            $ordinalByValue[$member] = $ordinal++;
        }
        $this->casesByValue = $casesByValue;
        $this->ordinalByValue = $ordinalByValue;
    }

    /**
     * @param scalar $raw
     *
     * @return list<\BackedEnum> the stored members, in enum declaration order
     */
    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): array
    {
        $stored = (string) $raw;
        if ('' === $stored) {
            return [];
        }

        $out = [];
        foreach (explode(',', $stored) as $token) {
            if (isset($this->casesByValue[$token])) {
                $out[] = $this->casesByValue[$token];
            }
        }
        usort($out, fn (\BackedEnum $a, \BackedEnum $b): int => $this->ordinalByValue[(string) $a->value] <=> $this->ordinalByValue[(string) $b->value]);

        return $out;
    }

    /**
     * @param mixed $value a list of the enum's cases (a set of flags); order and duplicates are irrelevant
     */
    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): string
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'SetCaster expected an array of %s; got %s.',
                $this->enum,
                get_debug_type($value),
            ));
        }

        $members = [];
        foreach ($value as $member) {
            if (!$member instanceof \BackedEnum || !is_a($member, $this->enum)) {
                throw new \InvalidArgumentException(sprintf(
                    'SetCaster expected each element to be a %s; got %s.',
                    $this->enum,
                    get_debug_type($member),
                ));
            }
            $members[(string) $member->value] = true;
        }
        $keys = array_keys($members);
        usort($keys, fn (string $a, string $b): int => $this->ordinalByValue[$a] <=> $this->ordinalByValue[$b]);

        return implode(',', $keys);
    }

    /**
     * The enum's case values, in declaration order — lets the schema builder derive a
     * `ColumnType::Set` column's `SET(...)` member list from the enum instead of a duplicated
     * inline `enumValues:` list (mirrors {@see EnumCaster::enumValues()} for `Enum` columns).
     *
     * @return list<string>
     */
    public function enumValues(): array
    {
        return array_keys($this->casesByValue);
    }
}
