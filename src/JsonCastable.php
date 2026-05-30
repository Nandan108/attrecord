<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * A value object that knows how to (de)serialize itself to/from a JSON payload,
 * for transparent casting on a `ColumnType::Json` column.
 *
 * Encoding uses the stdlib contract: {@see \JsonSerializable::jsonSerialize()} — which
 * `json_encode()` honors natively, so the caster needs no special encode path. Only the
 * decode half is added: {@see fromJson()} reconstructs an instance from the decoded
 * payload (an associative array).
 *
 * A `#[Column(ColumnType::Json)]` property typed as a JsonCastable class is auto-cast by
 * {@see Caster\JsonCaster} with no explicit `#[Cast]` needed.
 *
 * @api
 */
interface JsonCastable extends \JsonSerializable
{
    /**
     * Reconstruct an instance from a decoded JSON payload.
     *
     * @param array<array-key, mixed> $data the `json_decode($raw, true)` result
     */
    public static function fromJson(array $data): static;
}
