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
 * **`Locked` is not deadlock-*proof*, and the one shape it does not cover is a single key.** On
 * InnoDB, step 1 against a row that already exists leaves a **shared** lock on it (a plain
 * `INSERT`/`INSERT IGNORE` takes S where `ON DUPLICATE KEY UPDATE` would take X); step 2 then asks
 * for **exclusive** on that same row. Two sessions upserting the same existing row concurrently
 * therefore each hold S and each wait for X — a **lock-conversion deadlock**: one row, one
 * granularity, a mode conflict rather than an ordering one. (Conversion, not *escalation*, which
 * means row→page→table and is a different thing InnoDB does not do.)
 *
 * The ordered `FOR UPDATE` cannot help here, and this is not a gap in it: inversion needs two or
 * more resources to have an order at all, so with a single key there is nothing to order and the
 * protection has no grip. Reachability is correspondingly narrow — two writers, the same key,
 * overlapping in time — which is why it stays latent under single-writer flows.
 *
 * Callers who expect same-key concurrency should either wrap the call in a transaction-retry
 * decorator ({@see \Nandan108\Attrecord\Session\RetryingDbSession}, whose retry is what makes a
 * transient 1213 a non-event) or, for a homogeneous PK-keyed single-row write where the caveats
 * below are all harmless, consider `Lockless` — whose single statement has no S→X step to convert.
 *
 * `Lockless` — one single-statement `INSERT … VALUES (…),(…) ON DUPLICATE KEY UPDATE …`
 * (MySQL/MariaDB) / `… ON CONFLICT (pk) DO UPDATE SET …` (PostgreSQL/SQLite) — the engine's own
 * upsert, with **no** `SELECT … FOR UPDATE`. Taking no row locks is the point (hence the name), and
 * the trade: **the caller owns the concurrency implications** that `Locked`'s ordered locking
 * otherwise handles. Well-behaved for a PK-keyed coalescing queue/outbox (especially one written
 * *inside* an already-locked projection transaction, where the extra locks are actively undesirable),
 * riskier for secondary-unique-key contention. Trade-offs the caller must accept under `Lockless`:
 *
 * - **Conflict target is the PRIMARY KEY.** A table whose dedup key is a *secondary* unique key
 *   should make that key the PK, or use `Locked` / `upsertAllByUniqueKey()`.
 * - **Uniform SET.** Every row writes its own incoming value to each update column (no per-row
 *   masking), so a heterogeneous partial-record batch can clobber a column a given row never meant
 *   to change — use `Lockless` for homogeneous batches, `Locked` otherwise.
 * - **No id back-fill** and **no insert/update split**: the DB resolves insert-vs-update per row and
 *   reports only a single affected-row count. `SaveResult::$inserted` carries that raw driver count
 *   (on MySQL a changed row counts as 2) and `$updated` is 0. Use `Locked` for exact counts.
 *
 * @api
 */
enum UpsertStrategy
{
    case Locked;
    case Lockless;
}
