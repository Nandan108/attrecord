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
        if (null === $raw) {
            return null;
        }

        if ($col->isBool) {
            return (bool) (int) $raw;
        }

        if ($col->isInteger) {
            return (int) $raw;
        }

        if ($col->isFloat) {
            return (float) $raw;
        }

        if ($col->isDateTime) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $raw);

            return false !== $dt ? $dt : null;
        }

        if ($col->isDate) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $raw);

            return false !== $dt ? $dt : null;
        }

        // String and Binary — return as-is (binary is raw bytes from DB)
        return (string) $raw;
    }

    /**
     * Convert a typed PHP value to a scalar parameter suitable for DbSession::exec($sql, $params).
     *
     * Binary columns are passed as raw byte strings — DbSession implementations must handle them.
     */
    public static function toParam(mixed $value, ColumnDefinition $col): int | float | string | null
    {
        if (null === $value) {
            return null;
        }

        if ($col->isBool) {
            return (int) (bool) $value;
        }

        if ($col->isInteger) {
            return (int) $value;
        }

        if ($col->isFloat) {
            return (float) $value;
        }

        if ($col->isDateTime) {
            return $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d H:i:s')
                : (string) $value;
        }

        if ($col->isDate) {
            return $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d')
                : (string) $value;
        }

        // String + Binary (raw bytes passed as string)
        return (string) $value;
    }

    /**
     * Convert a typed PHP value to a canonical string for dirty-state comparison.
     *
     * Must produce the same string that the DB would return via fetchAll() for the same
     * value, so that snapshot[col] === toSnapshotString(current_value) means "clean".
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
