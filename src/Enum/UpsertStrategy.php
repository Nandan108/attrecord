<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/**
 * Strategy for {@see \Nandan108\Attrecord\RecordSet::upsertAll()}.
 *
 * `Locked` — the default and prior behaviour: the deadlock-safe three-step
 * `INSERT IGNORE`/`ON CONFLICT DO NOTHING` → `SELECT … ORDER BY pk ASC FOR UPDATE` → join-`UPDATE`.
 * The ordered `FOR UPDATE` acquires row locks deterministically, eliminating the lock-order
 * inversion that a bare `INSERT … ON DUPLICATE KEY UPDATE` can deadlock on under concurrency
 * (worst with secondary unique keys). Its join-UPDATE also masks per-row, so a heterogeneous batch
 * (records each carrying a different subset of changed columns) updates only each row's own fields.
 *
 * `Native` — one single-statement `INSERT … VALUES (…),(…) ON DUPLICATE KEY UPDATE …` (MySQL/MariaDB)
 * / `… ON CONFLICT (pk) DO UPDATE SET …` (PostgreSQL/SQLite). No `SELECT … FOR UPDATE`, so **the
 * caller owns the concurrency implications** — well-behaved for a PK-keyed coalescing queue/outbox
 * (especially one written *inside* an already-locked projection transaction, where the extra locks
 * are actively undesirable), riskier for secondary-unique-key contention. Trade-offs the caller must
 * accept under `Native`:
 *
 * - **Conflict target is the PRIMARY KEY.** A table whose dedup key is a *secondary* unique key
 *   should make that key the PK, or use `Locked` / `upsertAllByUniqueKey()`.
 * - **Uniform SET.** Every row writes its own incoming value to each update column (no per-row
 *   masking), so a heterogeneous partial-record batch can clobber a column a given row never meant
 *   to change — use `Native` for homogeneous batches, `Locked` otherwise.
 * - **No id back-fill** and **no insert/update split**: the DB resolves insert-vs-update per row and
 *   reports only a single affected-row count. `SaveResult::$inserted` carries that raw driver count
 *   (on MySQL a changed row counts as 2) and `$updated` is 0. Use `Locked` for exact counts.
 *
 * @api
 */
enum UpsertStrategy
{
    case Locked;
    case Native;
}
