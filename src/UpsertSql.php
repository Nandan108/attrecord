<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * The three SQL statements that form the deadlock-safe bulk-upsert pattern.
 *
 *   1. `create` — INSERT IGNORE for known-PK rows (inserts new, skips existing)
 *   2. `lock`   — SELECT pk … ORDER BY pk ASC FOR UPDATE (deterministic lock acquisition)
 *   3. `update` — CASE-based UPDATE for non-PK columns; null when nothing to update
 *
 * All three must execute inside the same transaction.
 *
 * @api
 */
final readonly class UpsertSql
{
    public function __construct(
        public string $create,
        public string $lock,
        public ?string $update,
    ) {
    }
}
