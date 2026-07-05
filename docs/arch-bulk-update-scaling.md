# Design: large-batch `saveAll()` scaling — join-based UPDATE

> **Status:** IMPLEMENTED in v0.2.0. Both halves are built: opt-in chunking (`saveAll(chunkSize:)`)
> and the single multi-mask join emitter (`UpsertJoinBuilder`, replacing the CASE UPDATE in all three
> dialects). This document is retained as the design rationale.
> **Depends on:** the per-row dirty-scoping contract landed in v0.1.3
> (`SqlDialect::buildUpsertSql(..., array $rowDirtyColumns = [])`).
> **Related:** [arch-concurrency.md](arch-concurrency.md) (the deadlock-safe 3-step upsert).

## 1. Problem

`RecordSet::saveAll()` emits **one** statement set for the whole record set (no chunking), and its
UPDATE step (step 3 of the deadlock-safe upsert) is a per-column `CASE`:

```sql
UPDATE t SET
  col = CASE id WHEN k1 THEN v1 WHEN k2 THEN v2 … WHEN kN THEN vN [ELSE col] END,
  …
WHERE id IN (k1, …, kN)
```

This is correct and clean, but has **two independent scaling limits**:

1. **Quadratic evaluation.** A `CASE id WHEN …` is evaluated top-to-bottom until a match. Updating
   the row at ladder position *j* costs ~*j* comparisons; summed over N rows per column that is
   **O(N²·M)** (N rows, M columns). Plus the pk literal is repeated in every column's CASE, so the
   statement **text** is ~2·N·M literals. At N≈100 this is invisible; at N≥~1,000 the server burns
   real CPU walking WHEN ladders and the SQL text balloons.
2. **Lock / undo footprint.** Step 2 takes `SELECT … FOR UPDATE` on all N rows in one transaction.
   Thousands of row locks + the undo/redo for one giant UPDATE is its own pressure, independent of
   the CASE cost.

MPB's legacy catalogue updater avoided (1) by joining an in-memory union sub-table (linear, indexed
join). We want the same for attrecord, portably, without giving up the per-row dirty scoping or the
deadlock-safe locking.

## 2. Two fixes, independent and composable

### 2a. Chunking (addresses limit 2, and caps limit 1)

Split the record set into slices and run the 3-step upsert per slice. This bounds statement size,
lock footprint, and undo size regardless of the UPDATE shape. **Deadlock-safety constraint:** the
whole point of step 2 is ascending-PK lock ordering, so **records must be globally sorted by PK
before chunking** — otherwise chunk A could lock pk 5 while chunk B (another txn/session) locks
pk 3 then 5, re-introducing lock-order inversion across chunks. Sort → chunk → each chunk locks a
contiguous ascending PK range. Chunk size configurable (default TBD — see §6).

**Transaction scope — DECIDED.** `saveAll()` is currently **atomic** (whole set in one transaction).
Chunking to bound the lock/undo footprint (limit 2) *requires* committing per chunk, which drops
whole-set atomicity — so the two are one decision, controlled by a **single argument**:

```php
saveAll(bool $force = false, ?int $chunkSize = null): ?SaveResult
```

- **`$chunkSize === null` (default)** — today's behaviour exactly: one atomic transaction, un-chunked,
  all-or-nothing. Byte-identical to now; zero risk to existing callers.
- **`$chunkSize` an int** — split into slices of that size and **commit per chunk**: bounded lock/undo
  footprint, but *not* all-or-nothing — a mid-run failure leaves earlier chunks committed, so the
  operation is resumable/idempotent. New records' `RETURNING`/`lastInsertId` back-fill and
  `markClean()` are applied per chunk as each commits.
  - **Rejected inside an open transaction (throws) — unless explicitly permitted.** Per-chunk commit
    is physically impossible when an outer `transactional()` is active — the outer transaction holds
    every lock until *it* commits, so nested chunks would silently accumulate into it and the
    footprint benefit would vanish. Rather than degrade silently (reproducing the very lock/undo
    blowup the caller chunked to avoid), `saveAll(chunkSize:)` throws an `AttrecordException` when
    `$session->inTransaction()`. For a bulk write inside a transaction, call `saveAll()` without
    `$chunkSize` (single atomic statement), move it outside, or pass **`allowInTransactionChunking:
    true`** to opt into the chunked-but-atomic mode below.

The chunk-size argument's *presence* is the opt-in; a separate `chunkCommit` flag would be redundant
(you cannot release locks mid-run and stay atomic).

**Third mode — "chunk statements but keep one atomic transaction" — supported via
`allowInTransactionChunking: true`.** This is the only way to atomically write a batch too large for
a single SQL statement. It falls out for free: a chunked `saveAll` called *inside* an outer
`transactional()` has each chunk's `transactional()` run **inline** (attrecord's sessions defer
commit to the outermost call), so the chunks execute as separate, smaller statements within the one
outer transaction — atomic, bounded statement size, footprint unbounded. Since the default rejects
nesting (above), the flag is the explicit "I know it won't bound the footprint" acknowledgement that
silences the throw. Lock ordering is unaffected — one transaction taking ascending-PK locks across
chunks is identical to the non-chunked atomic path. The flag has no effect outside a transaction.

Chunking alone already turns O(N²) into O(chunks · chunkSize²) = O(N · chunkSize), i.e. **linear in
N** for a fixed chunk size. So chunking is arguably the 80/20 win. The join form below removes the
remaining per-chunk quadratic factor.

### 2b. Join-based UPDATE (addresses limit 1 fully)

Replace the per-column `CASE` with a join against an inline derived table — MPB's device, now
portable across modern MySQL/PG/SQLite:

```sql
-- conceptual shape
UPDATE t
JOIN (VALUES … ) u(id, _mask, c1, c2, …) ON t.id = u.id
SET t.c1 = IF(u._mask & 1, u.c1, t.c1),
    t.c2 = IF(u._mask & 2, u.c2, t.c2),
    …
```

The optimizer builds an auto-key / hash on `u.id`, so it's **one indexed probe per row — O(N·M)**,
and the SET clause is **O(M)** regardless of N (fixed number of `IF`s). Text is ~N·M literals (no
pk duplication).

## 3. Why the join *needs* a runtime bitmask (the duality)

A set-based join has **one uniform SET expression per column**, applied to every joined row. It
therefore **cannot** bake per-row column selectivity at build time the way the `CASE` form does
(where we simply omit a `WHEN` for a row that didn't change the column). The selectivity must travel
**as data** — a per-row `_mask` integer with one bit per update column — and be tested at runtime:
`IF(u._mask & bit, u.col, t.col)`.

That is **exactly** MPB's `fieldMask`. The two designs are duals:

| | selectivity | evaluation |
|---|---|---|
| `CASE` (v0.1.3) | build-time (omit WHEN) | **O(N²·M)** |
| join + `_mask` (this) | runtime (data-carried bit) | **O(N·M)** |

The good news: the input we already compute for v0.1.3 — `$rowDirtyColumns` (per-row changed-column
sets) — is precisely what the mask is built from, so it feeds the join emitter directly.

**Multi-mask for any column count.** One integer holds 63 usable bits, so a table with > 63 update
columns simply uses *more* mask columns (`_mask0, _mask1, …`): for column ordinal `i`,
`maskIndex = ⌊i / 63⌋` and `bit = 1 << (i mod 63)` — bits 0–62 only, never bit 63 (the sign bit of a
64-bit signed integer). For the common case (≤ 63 columns) this is exactly one `_mask0`; wider tables
grow more mask columns from the *same* code, no branch. This makes the join a **single, uniform path
for every column count** — there is **no `CASE` fallback** (see §5). The v0.1.3 `CASE` builder is
deleted when the join lands.

## 4. Dialect SQL (concrete)

Let `updateColumns = [c1, c2, …]`; each row carries `(pk, _mask0[, _mask1…], v1, v2, …)` with
`bit(c_i) = 1 << (i mod 63)` in mask `⌊i / 63⌋` (§3). The examples below have ≤ 63 columns, so one
`_mask0` (shown as `_mask`).

**MySQL 8.0.19+** (`VALUES ROW()` table constructor; `IF()` and `&` are native):
```sql
UPDATE `t`
JOIN ( VALUES ROW(1, 3, 'a', 10), ROW(2, 1, 'b', 20) ) AS u(id, _mask, name, stock)
  ON `t`.`id` = u.id
SET `t`.`name`  = IF(u._mask & 1, u.name,  `t`.`name`),
    `t`.`stock` = IF(u._mask & 2, u.stock, `t`.`stock`);
```
Pre-8.0.19 (no `VALUES ROW`): use the `UNION SELECT` derived table (MPB's exact form) — same JOIN and
SET, only the sub-table constructor differs. So MySQL/MariaDB have no practical version floor, still
via the one join path (no `CASE`).

**PostgreSQL** (`UPDATE … FROM (VALUES …)`; `CASE WHEN (mask & bit) <> 0`; note typed literals):
```sql
UPDATE "t" SET
  "name"  = CASE WHEN (u._mask & 1) <> 0 THEN u.name  ELSE "t"."name"  END,
  "stock" = CASE WHEN (u._mask & 2) <> 0 THEN u.stock ELSE "t"."stock" END
FROM ( VALUES (1, 3, 'a'::text, 10::int), (2, 1, 'b'::text, 20::int) ) AS u(id, _mask, name, stock)
WHERE "t"."id" = u.id;
```
PG infers a `VALUES` column's type from its **first** row, and rejects mixed/untyped columns — so the
first row's literals must be typed (or all-typed). This is the **same typed-literal concern as the
v0.1.2 `CAST(NULL AS …)` fix**; `PgsqlDialect::toLiteral()` already emits typed nulls, and we extend
that discipline to the leading `VALUES` row (cast every first-row literal, cheap and unambiguous).

**SQLite** (`UPDATE … FROM`; `iif()`; `&` native):
```sql
UPDATE "t" SET
  "name"  = iif(u._mask & 1, u.name,  "t"."name"),
  "stock" = iif(u._mask & 2, u.stock, "t"."stock")
FROM ( VALUES (1, 3, 'a', 10), (2, 1, 'b', 20) ) AS u(id, _mask, name, stock)
WHERE "t"."id" = u.id;
```
`UPDATE … FROM` requires **SQLite ≥ 3.33.0** (released 2020-08-14). This is a **documented minimum
requirement**, not a runtime-detected capability: 3.33 is ~6 years old, so any project new enough to
adopt attrecord is already well past it, and a version-routing branch would be dead weight. Optional
nicety: a one-time guard in the SQLite connection-init that throws a clear "attrecord requires SQLite
≥ 3.33" error rather than letting an old engine emit a cryptic syntax error.

Steps 1 (`INSERT … IGNORE`/`ON CONFLICT`) and 2 (`SELECT … FOR UPDATE` ordered) are **unchanged** —
only step 3's shape switches.

## 5. No decision — one uniform join path (DECIDED)

There is **no CASE↔join switch**. The join is the *only* UPDATE emitter, for every batch size and
every column count. Two candidate reasons to branch were each examined and dismissed:

- **Batch size — rejected.** The join's only penalty over CASE is fixed per-statement overhead
  (derived-table materialization + auto-key build): microseconds for small N, and **swamped by the
  network round-trip both forms pay identically**. There is no batch size at which CASE is
  meaningfully faster end-to-end — and that overhead *is* what makes the join O(N·M). A size
  threshold would tune a fragile per-engine magic number to save sub-round-trip microseconds.
- **Column count (> 63) — dissolved by multi-mask (§3).** Extra mask columns handle any width from
  the same code, so the `_mask` bit ceiling stops being a special case. ~10 lines of index
  arithmetic, versus retaining a whole second (~120-line) emitter plus a gate.

- **Engine capability — a documented floor, not a branch.** `UNION SELECT` derived tables (MySQL/
  MariaDB), `UPDATE … FROM (VALUES …)` (PG, ancient), and SQLite ≥ 3.33 (§4) cover all supported
  engines. Nothing routes to an alternate shape.

So the v0.1.3 `CASE` builder (`buildUpsertCaseSet` + its step-3 loop, ~120 lines × 3 dialects) is
**deleted** when the join lands — a net code *reduction*. `RecordSet` computes the per-row masks from
the `$rowDirtyColumns` it already has and calls the one join builder. The differential-oracle role the
CASE builder could have played in tests is superseded by outcome-based tests (§7).

## 6. Open questions / decisions needed

1. **Chunk size default.** Chunking is still needed (§2a) to bound lock/undo/statement size, and its
   default *is* worth a quick benchmark (round-trips vs lock footprint) — but it's a single, robust
   number (e.g. 500–1000), not a per-engine shape threshold. Deployment-tunable via config with a
   sane default.
2. **Uniform-column optimization carries over.** A column changed by *every* row needs no mask test
   (`IF(mask & bit …)` is always true) → emit `u.col` directly. Mirrors the v0.1.3 "uniform → no
   ELSE" optimization; keeps the common homogeneous-batch fast and the mask smaller.
3. **Literals vs bound params.** attrecord inlines literals via `toLiteral()` today; the join form
   inlines the same way (MPB did), including the derived-table `VALUES`. Moving to bound params is a
   larger, orthogonal change (the whole library inlines) and is explicitly **out of scope** here — a
   separate future track.
4. **Sanity microbench (not threshold-finding).** Confirm there's no surprising small-N
   materialization cliff on MariaDB/MySQL/PG/SQLite (N ∈ {1, 5, 20}). This validates "join-always";
   it is not tuning a switch point.
5. **Empty-`_mask` / all-uniform batch.** With no per-row selectivity (every row changed every
   column) the mask is all-ones and every `IF` is trivially true — the join degrades to a plain
   `t.col = u.col`, still linear. Good.

## 7. Correctness strategy

- **Outcome-based behavioural tests (the oracle):** the existing v0.1.2/v0.1.3 integration tests —
  no-clobber (heterogeneous partial payload), null-clear, uniform-batch, marks-clean — now run
  through the join emitter on all three backends and assert actual resulting DB state. Because they
  check outcomes (not SQL strings), they are the real correctness oracle; the deleted CASE builder
  isn't needed as a differential reference.
- **Mask-arithmetic unit test:** ✅ `testBuildUpsertSqlMultiMaskSpillsBeyond63Columns` builds a
  64-sparse-column upsert and asserts the SQL: two mask columns `_m0`/`_m1`, ordinal 62 → `_m0 & (1<<62)`,
  ordinal 63 spills to `_m1 & 1`. Covers the 63-bit-group boundary at the SQL-generation level.
- **Wide-table (> 63 columns) DB integration test:** *deferred* — a real 64-column fixture Record is
  heavy boilerplate for low marginal value: the mask arithmetic is unit-covered above, and the
  join-on-a-real-DB path is integration-covered (clobber / null-clear / uniform / chunked) with fewer
  columns. Extra mask columns are just more `SELECT` columns; no engine limit is near.
- **Scale smoke test:** a multi-thousand-row batch (pathological under the old CASE), asserting it
  completes and is correct under the join.
- **Chunk-ordering test:** records are PK-sorted before chunking (lock-order invariant) — assert the
  per-chunk `FOR UPDATE` pk ranges are ascending and non-interleaved.

## 8. Rough effort

- New join builder (incl. multi-mask arithmetic) × 3 dialects, **replacing** the deleted CASE
  builder: ~1–1.5 days.
- RecordSet chunking + PK-sort + mask computation (from the dirty sets we already have): ~0.5–1 day.
- Outcome + mask-arithmetic + wide-table + scale + ordering tests across 3 backends: ~1 day.
- Chunk-size sanity bench (single default, not a per-engine threshold): ~0.25 day.
- **Total ~3 days**, and a **net line reduction** (delete ~120 lines of CASE per-dialect helper, add
  ~10 lines of multi-mask arithmetic). No gate, no size threshold, no version detection. The real cost
  is the three dialect SQL variants and their quirks (PG `VALUES` first-row typing, MySQL
  `UNION SELECT` derived table, SQLite `UPDATE…FROM` ≥ 3.33).

## 9. Recommendation

Ship **chunking first** (2a) as a small, high-value, low-risk change — it makes even the current CASE
form scale linearly and bounds the lock footprint, and the join wants bounded statement size anyway.
Then **replace the CASE emitter with the multi-mask join** (2b) as the single UPDATE path for all
sizes and column counts, deleting `buildUpsertCaseSet`. Both land in v0.2.0; chunking could be pulled
forward to a 0.1.x patch if large batches bite before v0.2.0 ships.

**Net shape:** *chunk always (PK-sorted) → one multi-mask join per chunk.* No CASE, no gate, no size
threshold, no version detection. Requires SQLite ≥ 3.33 (documented).
