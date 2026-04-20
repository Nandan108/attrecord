<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Converts values between PHP types and DB wire representations.
 *
 * Three distinct operations:
 *   fromDb()         — raw DB value (string|int|float|null from fetchAll) → typed PHP value
 *   toParam()        — typed PHP value → scalar|null for parameterised DbSession::exec()
 *   toSnapshotString() — typed PHP value → canonical string for dirty comparison
 *
 * @internal
 */
final class ColumnSerializer
{
    /**
     * Hydrate a raw DB value into the appropriate PHP type for the given column.
     *
     * @param scalar|null $raw
     */
    public static function fromDb(mixed $raw, ColumnDefinition $col): mixed
    {
        return match (true) {
            null === $raw    => null,
            $col->isBool     => (bool) (int) $raw,
            $col->isInteger  => (int) $raw,
            $col->isFloat    => (float) $raw,
            $col->isDateTime => self::tryParseDateTime((string) $raw, 'Y-m-d H:i:s'),
            $col->isDate     => self::tryParseDateTime((string) $raw, 'Y-m-d'),
            default          => (string) $raw, // String and Binary — return as-is (binary is raw bytes from DB)
        };
    }

    private static function tryParseDateTime(string $raw, string $format): ?\DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat($format, $raw);

        return false !== $dt ? $dt : null;
    }

    /**
     * Convert a typed PHP value to a scalar parameter suitable for DbSession::exec($sql, $params).
     *
     * Binary columns are passed as raw byte strings — DbSession implementations must handle them.
     */
    public static function toParam(mixed $value, ColumnDefinition $col): int | float | string | null
    {
        return match (true) {
            null === $value  => null,
            $col->isBool     => (int) (bool) $value,
            $col->isInteger  => (int) $value,
            $col->isFloat    => (float) $value,
            $col->isDateTime => $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d H:i:s')
                : (string) $value,
            $col->isDate => $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d')
                : (string) $value,
            default => $col->trimOnSave // String and Binary
                ? trim((string) $value)
                : (string) $value
        };
    }

    /**
     * Convert a typed PHP value to a canonical string for dirty-state comparison.
     *
     * Must produce the same string that the DB would return via fetchAll() for the same
     * value, so that snapshot[col] === toSnapshotString(current_value) means "clean".
     *
     * Because this delegates to toParam(), trimOnSave is applied here too. This means
     * setting a string property to a value that differs only in surrounding whitespace
     * is NOT considered dirty when trimOnSave is true — the record would write the same
     * trimmed bytes as what is already stored, so suppressing the UPDATE is correct.
     */
    public static function toSnapshotString(mixed $value, ColumnDefinition $col): ?string
    {
        if (null === $value) {
            return null;
        }

        $param = self::toParam($value, $col);

        return null === $param ? null : (string) $param;
    }
}
