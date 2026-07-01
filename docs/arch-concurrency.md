# Concurrency & production locking (design note, targeting 0.2.0)

> **Status:** design — decisions pinned here before implementation. Not yet built.
> **Theme:** make attrecord handle *production locking reality* across SQLite → MySQL → PG,
> with the concurrency machinery as **opt-in, prunable composition** rather than baked-in weight.

## 1. Thesis

attrecord already ships one concurrency-safety primitive: deadlock-safe, tier-ordered
`SELECT … FOR UPDATE` locking (`LockTier` / `LockSet`). That is a *multi-writer, row-locking*
story — it fits MySQL/MariaDB and PostgreSQL and is conceptually inert on SQLite (single
DB-level write lock, no row locks, no lock-order deadlocks).

But write-lock contention is universal — it just wears different clothes per engine. A lean
active-record that takes that reality seriously *across all three backends*, without forcing
unused code on deployments that don't need it, is a differentiated position. This note pins the
design for three additions that deliver it:

1. **Connection hardening** — per-backend connection-init (SQLite WAL + `busy_timeout` + FK
   pragma; optional MySQL/PG lock timeouts), *declared* by the dialect, *applied* by the session.
2. **Transient-conflict retry** — a `RetryingDbSession` decorator + per-backend
   `isRetryableTransactionError()` classification, so deadlock / serialization-failure /
   lock-wait-timeout / `SQLITE_BUSY` are retried with bounded backoff.
3. **`FOR UPDATE` dialect-gating** — required so SQLite (no `FOR UPDATE`) works, and cleaner
   regardless.

These pair naturally with the **SQLite dialect** (see §7); shipping the dialect *without* the
busy/retry layer would be a half-measure — it would "run" on SQLite but fall over under write
contention. Target them together as **0.2.0**.

## 2. Background — contention wears different clothes

| Backend | Concurrency model | Transient-conflict signal(s) |
|---|---|---|
| MySQL / MariaDB | row locks; deadlock detection | `1213` deadlock, `1205` lock-wait timeout, `1020` MariaDB MVCC "record has changed since last read" (REPEATABLE READ + `SELECT FOR UPDATE`) |
| PostgreSQL | row locks; serialization checks | SQLSTATE `40P01` deadlock_detected, `40001` serialization_failure |
| SQLite | **single DB-level write lock**, WAL for concurrent readers | `SQLITE_BUSY` (5), `SQLITE_LOCKED` (6) — "database is locked" |

Row-level `FOR UPDATE` locking is meaningful only in the first two. In SQLite the engine
serializes writers for you; the concern shifts from *deadlock avoidance* to *waiting out /
retrying the DB-level write lock* (`busy_timeout` + retry).

## 3. Decision 1 — connection hardening: dialect declares, session applies

The `SqlDialect` is a **pure SQL-string strategy** — no connection, no I/O. Protect that: the
dialect must not run PRAGMAs itself. Instead it **declares** the connection-init statements as
data; the session/`Connection` **executes** them once when the connection is opened.

```php
interface SqlDialect {
    /** @return list<string> statements to run once when a connection is opened (may be empty) */
    public function connectionInitStatements(): array;
}
```

- **`SqliteDialect(journalMode: 'WAL', busyTimeoutMs: 5000, foreignKeys: true)`** →
  `['PRAGMA journal_mode=WAL', 'PRAGMA busy_timeout=5000', 'PRAGMA foreign_keys=ON']`.
- **`MysqlDialect` / `PgsqlDialect`** → `[]` by default. The same hook can opt into
  `SET SESSION innodb_lock_wait_timeout=…` / `SET lock_timeout=…` if a consumer wants.

**Decisions:**

- **Do not force WAL.** It is a persistent, file-level mode and is a poor fit for some
  deployments (networked filesystems, read-only media). Make it a *default* that is configurable
  via the dialect constructor — mirroring how `MysqlDialect` already takes engine/charset/collation
  defaults.
- **`busy_timeout` is the first line of defence on SQLite** (it makes writers *wait* instead of
  immediately erroring); retry (§4) is the backstop for what the timeout doesn't absorb.
- **`foreign_keys=ON`** is off by default in SQLite — enable it by default so FK constraints
  emitted by `buildCreateTable` are actually enforced.
- **Application point:** `Connection` applies the dialect's init statements through the session
  the first time the connection is used (lazy, once). Rationale: `Connection` is the only object
  that holds *both* the session and the dialect, and it is the natural owner of "prepare this
  connection". Keep the sessions unaware of the dialect.

**Open question (O-1):** lazy-on-first-use vs eager-in-`Connection::__construct`. Lazy avoids
side effects in a constructor and cooperates with per-class connection overrides. Leaning lazy.

## 4. Decision 2 — transient-conflict retry

### 4.1 Retryable is a *policy*, not a fixed fact

The load-tuned retry in InvFlux's `WpdbMysqlSession` (100 VUs, 10 attempts, tuned backoff)
established the key constraint: it deliberately **does not retry deadlocks (1213)**, because
InvFlux's lock-order discipline forbids them — a deadlock there is a *bug to surface*, not a
transient to mask. It retries `1020` (MariaDB MVCC) and `1205` (lock-wait timeout) instead.

Therefore a single baked-in per-backend list is wrong. The design:

- **`DbSession::isRetryableTransactionError(\Throwable): bool`** — a per-backend **default**
  classifier (sibling of the existing `isDuplicateKeyError()`), which **includes deadlocks** by
  default, because most consumers do *not* have InvFlux's lock discipline and genuinely want
  deadlock retries.
- **`RetryingDbSession` accepts an override predicate** so a consumer (InvFlux) can say "1020/1205
  but *not* 1213" and keep its policy.

Default classifiers:

| Backend | Default retryable codes |
|---|---|
| MySQL / MariaDB | `1213` (deadlock), `1205` (lock-wait timeout), `1020` (MariaDB MVCC) |
| PostgreSQL | `40P01` (deadlock_detected), `40001` (serialization_failure) |
| SQLite | `5` (`SQLITE_BUSY`), `6` (`SQLITE_LOCKED`) |

### 4.2 `RetryingDbSession` — an opt-in decorator

```php
final class RetryingDbSession implements DbSession {
    public function __construct(
        private DbSession $inner,
        private int $maxAttempts = 10,
        private int $baseDelayUs = 5_000,     // 5 ms
        private int $maxDelayUs = 100_000,    // 100 ms cap per attempt
        private ?\Closure $retryable = null,  // (\Throwable): bool — defaults to $inner->isRetryableTransactionError(...)
    ) {}

    public function transactional(\Closure $op): mixed {
        // Only retry the OUTER transaction; a nested call passes straight through.
        if ($this->inner->inTransaction()) {
            return $this->inner->transactional($op);
        }
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->inner->transactional($op);
            } catch (\Throwable $e) {
                $isRetryable = $this->retryable
                    ? ($this->retryable)($e)
                    : $this->inner->isRetryableTransactionError($e);
                if ($attempt >= $this->maxAttempts || !$isRetryable) {
                    throw $e;
                }
                $this->backoff($attempt); // exponential, capped, up-to-50% jitter
            }
        }
    }

    // every other DbSession method delegates verbatim to $inner
}
```

Why a decorator (vs. baking retry into each session or into `Record::transactional()`):

- **Zero cost if unused** — don't wrap, don't pay; fully deletable for a minimal vendored copy.
  This is the "prunable / power-to-weight" requirement made literal.
- **No API churn** — `Record::transactional()` and `RecordSet::saveAll()`'s internal
  `session->transactional()` gain retry automatically, because they already funnel through
  `transactional()`. The decorator intercepts once.
- **Nesting composes** — inner `transactional()` calls see `inTransaction() === true` and run
  inline; only the outermost retries the whole unit.
- **Cleaner than the InvFlux inline loop** — the decorator wraps at the `transactional()`
  boundary and never touches `START/COMMIT/ROLLBACK` itself; the inner session owns transaction
  mechanics. It even composes *with* an inner session that sets isolation per attempt (see §4.4).

Backoff defaults are lifted from InvFlux's tuned values: base 5 ms, ×2 per attempt, capped at
100 ms/attempt, up-to-50% jitter → worst-case total wait across 9 retries ≈ 555 ms (vs. ~2.5 s
uncapped).

### 4.3 The idempotency contract (must be loud)

Retry **re-runs the closure**. Any effect inside it that is *not* rolled back by the database —
HTTP calls, queue publishes, file writes, in-memory mutations — will repeat. The contract:
**closures passed to `transactional()` under a `RetryingDbSession` must be safe to re-run**
(pure-SQL, or side-effect-free outside the DB). This gets a bold callout in the README and the
`RetryingDbSession` docblock.

### 4.4 What stays *out* of the generic primitive

InvFlux's loop issues `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` before each attempt —
that is what actually *prevents* MariaDB's `1020` under REPEATABLE READ + `SELECT FOR UPDATE`.
That is a workload/engine-specific *semantics* decision (READ COMMITTED changes read behaviour)
and must **not** be forced by a generic ORM retry. Options for a consumer that wants it:

- set a default isolation level via `connectionInitStatements()` (per connection), or
- keep it inside their own session's `transactional()` (it re-applies on each decorator retry).

attrecord's primitive stays: *classify → backoff → retry*.

## 5. Decision 3 — `FOR UPDATE` dialect-gating

`FOR UPDATE` is currently hardcoded in three places outside the dialects, plus the dialects'
bulk-upsert lock step:

- `LockSet::acquire()` — `src/LockSet.php`
- `Record::find(forUpdate: true)` — `src/Record.php`
- `Record::buildSelectSql()` (for `getOne(forUpdate: true)`) — `src/Record.php`
- `Mysql/PgsqlDialect::buildUpsertSql()` step 2

SQLite has no `FOR UPDATE`, so these must become dialect-provided:

```php
interface SqlDialect {
    /** e.g. 'FOR UPDATE' on MySQL/PG; '' on SQLite. */
    public function forUpdateClause(): string;
}
```

- **SQLite** returns `''`. `find(forUpdate: true)` / `getOne(forUpdate: true)` become silent
  no-ops there — correct, since a subsequent write in the same transaction takes SQLite's
  DB-level write lock anyway.
- **Bulk upsert on SQLite:** the 3-step *INSERT-IGNORE → SELECT FOR UPDATE → CASE UPDATE* pattern
  exists to avoid deadlocks under row locking; SQLite has neither the risk nor `FOR UPDATE`. So
  `SqliteDialect::buildUpsertSql()` should emit a **single-statement** bulk
  `INSERT … ON CONFLICT(cols) DO UPDATE SET x = excluded.x`, with no lock step.

**Decision (D-1):** make `UpsertSql::$lock` **nullable** (like `$update`) and have
`RecordSet::saveAll()` skip a null lock step. This lets any dialect do a single-statement upsert
where safe (SQLite always; potentially an opt-in fast path for MySQL/PG small batches later).

## 6. Prunability / power-to-weight

The whole design keeps concurrency machinery as *composition*, so a deployment takes only what it
needs:

- Don't use `RetryingDbSession` → no retry code runs (and the file can be deleted from a vendored
  copy).
- MySQL-only bundle → delete `PgsqlDialect` / `SqliteDialect` (already true today; see the InvFlux
  bundle-pruning discussion).
- Don't use `LockSet` / advisory locks → they simply aren't referenced.

PHP has no tree-shaking, but unreferenced classes are never autoloaded, so "prunable" is both a
runtime reality (zero cost) and a literal one (safe to delete files).

## 7. SQLite dialect specifics (companion work)

The dialect itself largely mirrors `PgsqlDialect`, with these engine quirks:

- **Auto-increment PK quirk:** an auto-increment PK **must** be `INTEGER PRIMARY KEY AUTOINCREMENT`
  inline on the column, and you must **not** also emit a separate `PRIMARY KEY (id)` clause.
  Special-case single-column auto-increment PKs in `buildCreateTable`.
- **Type affinity:** `VARCHAR(n)`→`TEXT`, `DECIMAL`→`NUMERIC`, `BOOL`→`INTEGER`, `BINARY`/`BYTEA`
  →`BLOB`, `JSON`→`TEXT`, datetimes→`TEXT`. Length/precision mostly advisory.
- **No comments** — SQLite has no `COMMENT ON` / inline comments; drop them.
- **Indexes** — separate `CREATE INDEX` statements (like PG).
- **FKs** — DDL is fine, but enforcement needs `PRAGMA foreign_keys=ON` (handled by §3).
- **Binary binding** — verify `PDO::PARAM_LOB` behaviour on SQLite; set `bindsBinaryAsLob()`
  accordingly.
- **`RETURNING`** — available 3.35+, but PDO_sqlite `lastInsertId()` is reliable, so
  `insertReturningSuffix()` returns `''`.
- **Test harness** — no `TRUNCATE`; the SQLite integration base resets via `DELETE FROM t` +
  `DELETE FROM sqlite_sequence WHERE name = 't'`. `LIKE` is ASCII-case-insensitive by default —
  a couple of assertions may need `@group`-specific handling. In-memory (`:memory:`) or a temp
  file, so CI needs no service container.

## 8. InvFlux migration (dogfood)

Once the primitives land, InvFlux's `WpdbMysqlSession` can drop its bespoke
`runOuterTransactionWithRetry` loop and instead:

1. keep its `SET ISOLATION READ COMMITTED` + `START/COMMIT` in `transactional()`,
2. wrap the session in `RetryingDbSession` with a **custom predicate** (`1020`/`1205`, *not*
   `1213`) and its tuned budget.

InvFlux ends up *simpler* (loses the loop, keeps only policy + isolation choice) — the "does this
belong in the library" test passing in practice.

## 9. Decisions & open questions

- **D-1:** `UpsertSql::$lock` becomes nullable; `saveAll()` skips a null lock step. *(pinned)*
- **D-2:** default `isRetryableTransactionError()` **includes** deadlocks; consumers opt *out* via
  the override predicate. *(pinned)*
- **D-3:** isolation-level management is **not** in the generic retry. *(pinned)*
- **O-1:** connection-init applied lazily on first use vs eagerly in `Connection::__construct`.
  *(leaning lazy)*
- **O-2:** does `find(forUpdate: true)` on SQLite silently no-op, or warn? *(leaning silent
  no-op + docs)*
- **O-3:** should `RetryingDbSession` expose a hook for observability (per-attempt callback), given
  InvFlux wanted a profiler? *(leaning: a nullable `onRetry` callback, kept optional)*

## 10. Sequencing (0.2.0)

1. `forUpdateClause()` dialect-gating refactor (D-1 included) — load-bearing; benefits MySQL/PG
   regardless. Re-test both existing backends.
2. `connectionInitStatements()` + `Connection` application.
3. `SqliteDialect` + `SqliteIntegrationTestCase` + dual-run (now tri-run) suites + a
   `SqliteDialectCreateTableTest`.
4. `isRetryableTransactionError()` per backend + `RetryingDbSession` + tests (force a real
   deadlock on MySQL/PG; force `SQLITE_BUSY` on SQLite).
5. Docs: README "Concurrency" section + the idempotency contract; link this note.
6. CHANGELOG `0.2.0`.
