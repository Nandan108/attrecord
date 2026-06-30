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
     * When the column declares a caster it is authoritative: the raw value (and the
     * full row, for discriminator-aware casters) is handed to it and native type
     * handling is skipped. Casters are never invoked for a null raw value.
     *
     * @param scalar|resource|null       $raw a scalar, or a stream resource for a PostgreSQL bytea column
     * @param array<string, scalar|null> $row full raw row being hydrated (for casters that read sibling columns)
     */
    public static function fromDb(mixed $raw, ColumnDefinition $col, array $row = []): mixed
    {
        if (\is_resource($raw)) {
            // PostgreSQL (PDO_pgsql) returns a bytea column as a stream resource; read it once
            // into the raw bytes so the arms below (and any caster) see a plain string.
            $raw = (string) \stream_get_contents($raw);
        }

        return match (true) {
            null === $raw         => null,
            null !== $col->caster => $col->caster->fromDb($raw, $row, $col),
            // MySQL returns 1/0 (int); PostgreSQL returns 't'/'f' (string) for BOOLEAN columns.
            $col->isBool     => \in_array($raw, [true, 1, '1', 't', 'true'], true),
            $col->isInteger  => (int) $raw,
            // A Decimal/float column bound to a string-typed property keeps its exact decimal
            // string (PDO already returns DECIMAL as a string) instead of a lossy float cast.
            $col->isFloat    => 'string' === $col->phpType ? (string) $raw : (float) $raw,
            $col->isDateTime => self::tryParseDateTime((string) $raw, ['Y-m-d H:i:s.u', 'Y-m-d H:i:s']),
            $col->isDate     => self::tryParseDateTime((string) $raw, ['Y-m-d']),
            default          => (string) $raw, // String and Binary — return as-is (raw bytes for binary)
        };
    }

    /**
     * Parse a DB datetime/date string, trying each format in order (first that parses
     * wins). The fractional-seconds format is tried first so DATETIME(n) values — which
     * MySQL returns as `…:56.000000` — round-trip instead of failing; precision-0 values
     * fall through to the plain format.
     *
     * @param non-empty-list<string> $formats
     */
    private static function tryParseDateTime(string $raw, array $formats): ?\DateTimeImmutable
    {
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if (false !== $dt) {
                return $dt;
            }
        }

        return null;
    }

    /**
     * Apply the column caster (rich value → DB scalar) when one is set and the value is
     * non-null; otherwise return the value unchanged. Used by both write paths: the
     * parameter path via toParam(), and the bulk-literal path in RecordSet::buildPlan()
     * before SqlDialect::toLiteral().
     */
    public static function toDbValue(mixed $value, ColumnDefinition $col): mixed
    {
        return (null !== $value && null !== $col->caster)
            ? $col->caster->toDb($value, $col)
            : $value;
    }

    /**
     * Convert a typed PHP value to a scalar parameter suitable for DbSession::exec($sql, $params).
     *
     * A column caster, when set, is authoritative: its output is the bound scalar and the
     * native type handling below is bypassed (so e.g. an EpochCaster on an integer column
     * is not re-coerced by the integer arm).
     *
     * Binary columns without a caster are returned as raw byte strings by default. When
     * $bindBinaryAsLob is true (the active dialect requires it — PostgreSQL), they are instead
     * wrapped in a {@see BinaryParam} so the session can bind them as a LOB rather than text.
     * Leaving it false keeps the bound value a plain scalar, so a MySQL consumer's custom
     * DbSession that only accepts scalars is unaffected. The flag comes from
     * {@see SqlDialect::bindsBinaryAsLob()} and is passed by the binding call sites only — the
     * snapshot/export paths keep the default (raw string).
     */
    public static function toParam(mixed $value, ColumnDefinition $col, bool $bindBinaryAsLob = false): int | float | string | BinaryParam | null
    {
        if (null !== $value && null !== $col->caster) {
            return $col->caster->toDb($value, $col);
        }

        if (null !== $value && $bindBinaryAsLob && $col->isBinary) {
            return new BinaryParam($col->trimOnSave ? trim((string) $value) : (string) $value);
        }

        return match (true) {
            null === $value  => null,
            $col->isBool     => (int) (bool) $value,
            $col->isInteger  => (int) $value,
            // String-typed money/decimal binds as its exact string (the driver handles DECIMAL),
            // avoiding a float round-trip that could drop precision on wider scales.
            $col->isFloat    => 'string' === $col->phpType ? (string) $value : (float) $value,
            // Honor declared fractional-seconds precision — see MysqlDialect for the
            // matching rationale (literal path). Without this, bound-param writes drop
            // microseconds from sentinels like '9999-12-31 23:59:59.999999'.
            $col->isDateTime => $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d H:i:s'.(($col->precision ?? 0) ? '.u' : ''))
                : (string) $value,
            $col->isDate => $value instanceof \DateTimeImmutable
                ? $value->format('Y-m-d')
                : (string) $value,
            default => $col->trimOnSave // String
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
