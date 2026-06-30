<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * A bound query parameter carrying raw binary bytes.
 *
 * Why this exists: binding raw bytes as an ordinary string parameter is unsafe on some
 * drivers. PostgreSQL (PDO_pgsql) treats a positional `?` parameter as UTF-8 text and
 * rejects any byte sequence that is not valid UTF-8 with a "Character not in repertoire"
 * error. To bind a `bytea` value the driver must be told the parameter is a LOB. A plain
 * `scalar` parameter carries no such type information, so {@see ColumnSerializer::toParam()}
 * wraps the bytes of a {@see Enum\ColumnType} `Binary`/`VarBinary` column in this marker.
 *
 * `DbSession` implementations recognise the wrapper and bind it with the driver-appropriate
 * binary mechanism:
 *  - PDO   → `bindValue($i, $bytes, PDO::PARAM_LOB)` (works for MySQL BLOB/BINARY and PG bytea)
 *  - mysqli/wpdb → unwrapped to the raw byte string (MySQL accepts binary in a string bind)
 *
 * The wrapper stringifies to the raw bytes, so the dirty-tracking snapshot path
 * ({@see ColumnSerializer::toSnapshotString()}) compares bytes on both sides.
 *
 * Application code rarely constructs this directly — the serializer produces it for mapped
 * binary columns. Construct it explicitly only to bind a binary value in an ad-hoc
 * {@see WhereClause} predicate, where no column metadata is available to drive the wrapping.
 *
 * @api
 */
final class BinaryParam implements \Stringable
{
    public function __construct(public readonly string $bytes)
    {
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->bytes;
    }
}
