<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Caster;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Opt-in enum caster: maps a PHP **backed enum** to/from its scalar backing value, for a column
 * declared with a matching scalar ColumnType (an `Int*` type for an int-backed enum; `VarChar`/`Char`/
 * `Enum` for a string-backed one). Never auto-attached.
 *
 * Usage: `#[EnumCaster(MyStatus::class)]` on a property typed as the enum (or `?enum`), e.g.
 *
 *   #[Column(ColumnType::TinyIntUnsigned, default: 1)]
 *   #[EnumCaster(MyStatus::class)]
 *   public MyStatus $status = MyStatus::Initial;
 *
 * The raw DB scalar is normalized to the enum's backing type before `::from()` (drivers may return an
 * int column as a numeric string), so both int- and string-backed enums round-trip. The framework
 * short-circuits null on both sides (see {@see \Nandan108\Attrecord\ColumnCaster}).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EnumCaster extends Cast
{
    private readonly bool $intBacked;

    /** @param class-string<\BackedEnum> $enum */
    public function __construct(private readonly string $enum)
    {
        $backing = (new \ReflectionEnum($enum))->getBackingType();
        if (!$backing instanceof \ReflectionNamedType) {
            throw new \InvalidArgumentException(sprintf('EnumCaster requires a backed enum; %s is not backed.', $enum));
        }
        $this->intBacked = 'int' === $backing->getName();
    }

    #[\Override]
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): \BackedEnum
    {
        /** @var scalar $raw */
        return $this->enum::from($this->intBacked ? (int) $raw : (string) $raw);
    }

    #[\Override]
    public function toDb(mixed $value, ColumnDefinition $col): int | string
    {
        \assert($value instanceof \BackedEnum);

        return $value->value;
    }
}
