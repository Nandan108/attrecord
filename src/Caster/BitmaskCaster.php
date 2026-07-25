<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Caster;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Portable flag-set caster: maps a PHP **set of enum members** (`list<E>`) to/from an integer
 * **bitmask** stored in a single integer column. Works on every backend — the column is a plain
 * `Int*` type, and membership is an ordinary integer, so there is no dialect-specific storage.
 *
 * The enum must be **int-backed with distinct positive power-of-two case values** (`1, 2, 4, 8, …`) —
 * each case owns one bit. This is validated once, at schema-build time. A "none"/zero pseudo-member
 * does not belong in a bitmask enum (0 is not a power of two); the empty set *is* the zero mask.
 *
 * Usage — declare the caster on an integer-typed, `array`-typed property:
 *
 *   #[Column(ColumnType::BigIntUnsigned, default: 0)]
 *   #[BitmaskCaster(StockConcern::class)]
 *   public array $concerns = [];        // e.g. [StockConcern::Deficit, StockConcern::NoCost]
 *
 * On write the members are OR-ed into one integer; on read the integer is decomposed back into the
 * members whose bit is set, **in the enum's declaration order** (canonical). Because the stored value
 * is the mask, dirty-tracking is order-independent for free: `[A, B]` and `[B, A]` yield the same
 * integer, hence the same snapshot. The framework short-circuits null on both sides
 * (see {@see \Nandan108\Attrecord\ColumnCaster}); a nullable column distinguishes "unset" (NULL) from
 * "empty set" (0).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class BitmaskCaster extends Cast
{
    /**
     * bit value => case, keyed by the (power-of-two) backing value and iterated in declaration order.
     *
     * @var array<int, \BackedEnum>
     */
    private readonly array $casesByBit;

    /** @param class-string<\BackedEnum> $enum an int-backed enum whose case values are distinct powers of two */
    public function __construct(private readonly string $enum)
    {
        $backing = (new \ReflectionEnum($enum))->getBackingType();
        if (!$backing instanceof \ReflectionNamedType || 'int' !== $backing->getName()) {
            throw new \InvalidArgumentException(sprintf(
                'BitmaskCaster requires an int-backed enum; %s is not int-backed.',
                $enum,
            ));
        }

        $casesByBit = [];
        foreach ($enum::cases() as $case) {
            /** @var int $bit */
            $bit = $case->value;
            if ($bit <= 0 || 0 !== ($bit & ($bit - 1))) {
                throw new \InvalidArgumentException(sprintf(
                    'BitmaskCaster requires every case of %s to be a positive power of two; %s = %d is not.',
                    $enum,
                    $case->name,
                    $bit,
                ));
            }
            // Reject two cases sharing a bit. PHP 8.1 forbids duplicate backed-enum values at compile
            // time, but 8.2+ defers that check to the first `from()`/`tryFrom()` — so a collision can
            // reach here (via `cases()`) and would otherwise be silently swallowed. Catch it early.
            if (isset($casesByBit[$bit])) {
                throw new \InvalidArgumentException(sprintf(
                    'BitmaskCaster: %s has two cases sharing bit %d (%s and %s).',
                    $enum,
                    $bit,
                    $casesByBit[$bit]->name,
                    $case->name,
                ));
            }
            $casesByBit[$bit] = $case;
        }
        $this->casesByBit = $casesByBit;
    }

    /**
     * @param scalar $raw
     *
     * @return list<\BackedEnum> the members whose bit is set in the mask, in enum declaration order
     */
    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): array
    {
        $mask = (int) $raw;
        $out = [];
        foreach ($this->casesByBit as $bit => $case) {
            if (($mask & $bit) === $bit) {
                $out[] = $case;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value a list of the enum's cases (a set of flags); order and duplicates are irrelevant
     */
    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): int
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'BitmaskCaster expected an array of %s; got %s.',
                $this->enum,
                get_debug_type($value),
            ));
        }

        $mask = 0;
        foreach ($value as $member) {
            if (!$member instanceof \BackedEnum || !is_a($member, $this->enum)) {
                throw new \InvalidArgumentException(sprintf(
                    'BitmaskCaster expected each element to be a %s; got %s.',
                    $this->enum,
                    get_debug_type($member),
                ));
            }
            /** @var int $bit */
            $bit = $member->value;
            $mask |= $bit;
        }

        return $mask;
    }
}
