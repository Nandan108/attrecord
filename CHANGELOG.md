# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] - 2026-07-22 — Optimistic locking

### Added

- **`#[Version]` — optimistic locking.** Marks an integer column as the record's version, so a
  concurrent write is **detected** rather than silently lost. attrecord seeds it to `1` on INSERT;
  every single-record `save()` UPDATE then emits `SET … <ver> = <ver> + 1 … WHERE pk = ? AND
  <ver> = ?` against the value the record was loaded with, and raises the new
  **`OptimisticLockException`** (carrying `recordClass`, `id`, `expectedVersion`) when no row matches
  — because another writer moved the row on, or deleted it. On success the in-memory value is bumped
  to match. At most one per Record; must be an integer column and must not be generated.

  This covers the conflicts `SELECT … FOR UPDATE` cannot: a pessimistic lock only holds *within one
  transaction*, so it does nothing when the read and the write happen in **different requests** (load
  a form, submit it minutes later). Detection is free on both write paths — affected-rows on MySQL,
  and "no row returned" on the PostgreSQL/SQLite `RETURNING` path added in 0.7.0. And because the
  UPDATE always increments the version, a matched row always genuinely changes, so MySQL's
  changed-rows (rather than matched-rows) reporting cannot masquerade as a false conflict.

- **Version handling on the bulk paths.** `insertAll()` and `upsertAll()` seed the version on new
  records. The set-based updates — `updateWhere()` / `updateByWhere()` / `updateByUniqueKey()` —
  **bump** it (alongside the existing `#[UpdatedAt]` injection), unless the caller sets the column
  explicitly. They cannot *guard*, since they match rows by predicate rather than from loaded state
  and so have no per-row expected value; but bumping is essential — leaving the version untouched
  would let a stale holder's guarded write match afterwards and clobber the update.

  **Not yet covered:** the keyed **bulk upsert** (`upsertAll()`'s CASE-UPDATE, and therefore
  `upsertAllByUniqueKey()` and the chunked path) neither guards nor bumps. Doing so needs a per-row
  `(pk, version)` row-constructor predicate plus a way to report *which* rows lost, and is deferred to
  a follow-up. Use `save()` where the guard matters. (Doctrine's optimistic locking is likewise
  per-entity only.)

## [0.7.0] - 2026-07-21 — Selective write read-back

### Added

- **`ignoreColumns` on the write paths** — a **subtractive** column-name denylist: the listed
  columns are dropped from the generated statement. On **INSERT** their DB default fires — this is
  the only way to reach a **nullable** column's default (a nullable column is otherwise always
  written as its `null`). On **UPDATE** they stay out of the `SET`, so a column can be preserved or
  an `#[UpdatedAt]` bump skipped. `null` / `[]` ignore nothing (unchanged behavior); an unknown
  column name throws `SchemaException`. Added to:
  - `Record::save(bool $force = false, ?array $ignoreColumns = null)`
  - `RecordSet::insertAll(?array $ignoreColumns = null)` — dropped from the bulk INSERT column list.
  - `RecordSet::upsertAll(..., ?array $ignoreColumns = null)` — dropped from both the plain-INSERT
    branch and the keyed-upsert membership / `SET` (the introspection helpers
    `buildInsertAllSql()` / `buildUpsertAllSql()` take it too).

  The single/bulk **unique-key** upsert paths (`upsertByUniqueKey()` / `upsertAllByUniqueKey()`) are
  unchanged.
- **`readBack` on the write paths** — `save(..., bool|list<string>|null $readBack = null)`,
  `insertAll(..., $readBack)`, `upsertAll(..., $readBack)`. After the write, re-read column(s) and
  re-hydrate the record(s) so values the write omitted — an ignored column whose DB default fired, or
  a generated column — reflect their stored form and the record reads back **clean** (fixes both
  properties and the dirty-snapshot). Without it, dropping a defaulted column leaves the record
  marked clean while its in-memory value diverges from the DB, so a later plain `save()` could
  clobber the default. Forms: **`true`** reloads the whole row (via `hydrateFromRow()`, fires
  `afterLoad()`); **`false`** never; a **`list<string>`** reads back exactly those columns — a
  targeted patch (no `afterLoad`; unknown name throws `SchemaException`), for naming a
  trigger-populated column auto can't infer; **`null` = auto** reads back every column attrecord's
  own write left diverged — on INSERT each omitted default-bearing column whose DB default fired (an
  ignored one, or a NOT-NULL null-with-default dropped by the insert rule), and on any write the
  **generated** columns a written column feeds into (found by scanning each generated column's
  expression for the column names it references, transitively) — and **nothing** when nothing
  diverged, so it costs nothing on that path. This closes the divergence the NOT-NULL-default insert
  rule introduced in 0.6.1 (record clean but its in-memory value stale) by default, without a
  read-back on writes that populated no DB-side value. `save()` re-reads by PK; the bulk writers use a
  single batched `IN` query (ascending-PK, binary-safe), never a per-row loop.
- **`save()` folds its read-back into the write's `RETURNING` clause** on dialects that support it
  (PostgreSQL, SQLite), scoped to exactly the diverged columns (`… RETURNING <pk>, <cols>` on INSERT;
  `UPDATE … RETURNING <cols>`) — the value comes back in the **same round-trip**, no separate SELECT.
  MySQL/MariaDB (no `UPDATE … RETURNING`) fall back to the scoped `SELECT`. New dialect capability
  `SqlDialect::supportsReturning()`. The bulk writers still use their single batched read-back
  `SELECT` (folding it into the multi-step keyed upsert is left for later).

## [0.6.1] - 2026-07-21

### Fixed

- **`save()` now lets a DB default fire for a NOT-NULL column left null on INSERT.** Previously an
  INSERT emitted every non-generated column, so a NOT-NULL column with a `default` / `defaultExpr`
  (e.g. `recorded_at DEFAULT CURRENT_TIMESTAMP`) left `null` was written as an explicit `NULL` —
  which raised a NOT-NULL violation and made the DB default unreachable through the ORM. Such a
  column is now **omitted** from the INSERT so its default takes effect. A **nullable** column with a
  default is deliberately left alone (its `null` is still written — `null` may mean "store NULL", not
  "use the default"). This aligns single-record `save()` with the bulk `upsertAll()` / `insertAll()`
  paths, which already drop an all-null column from the statement.

## [0.6.0] - 2026-07-19 — Append-only writes & bulk-verb naming

### Added

- **`RecordSet::insertAll()`** — a plain **insert-only** bulk writer for append-only tables
  (ledgers, event logs, outboxes): one `INSERT INTO … VALUES (…), (…)` over the whole set in a
  single transaction, with **no upsert semantics** — a duplicate PK raises a DB error (wrapped in
  `RecordSaveException`) instead of being silently ignored or overwriting an immutable row, and no
  `SELECT … FOR UPDATE` locks are taken (unlike a PK-carrying record in `upsertAll()`, which routes
  into the keyed upsert). Works whether the PK is DB-generated (auto-increment ids are back-filled
  onto the records in INSERT order) or application-minted; a batch must be homogeneous — all PK-null
  on an auto-increment table, or all PK-carrying on a minted-PK table. Runs the full per-record
  lifecycle, stamping `#[CreatedAt]`/`#[UpdatedAt]` **as new** for every row (including minted,
  non-null PKs). `buildInsertAllSql()` exposes the SQL for introspection.
- **`AppendOnly` marker interface** — declare a Record write-once (ledgers, event logs, outboxes,
  audit trails). Reads stay unrestricted; the only permitted write is an INSERT (`insertAll()`, or
  `save()` on a new record). Every mutating path — `save()` on an existing row, `delete()`,
  `deleteAll()`, `deleteWhere()`, `updateWhere()`, `updateByWhere()`, `upsertAll()`,
  `upsertAllByUniqueKey()` — throws `AppendOnlyViolationException`. `upsertAll`/`upsertAllByUniqueKey`
  are rejected outright (not only when they would upsert): their insert-vs-upsert choice is per-record
  at runtime, so neither is a reliable append — use `insertAll()`. Enforced at runtime, so bulk and
  instance paths are both covered.

### Changed

- **`RecordSet::saveAll()` → `upsertAll()`** — renamed so the bulk-write family all name their SQL
  verb (`deleteAll` / `insertAll` / `upsertAll` / `upsertAllByUniqueKey`); `upsertAll()` also pairs
  cleanly with `upsertAllByUniqueKey()` (same verb, differing conflict target). What it does is
  unchanged: plain INSERT for PK-null records, 3-step keyed upsert for PK-carrying ones. Likewise
  `buildSaveAllSql()` → `buildUpsertAllSql()`. The single-record `save()` is deliberately **not**
  renamed. **BC:** `saveAll()` and `buildSaveAllSql()` are kept as `@deprecated` forwarding aliases,
  so existing call sites keep working (consumer psalm will flag `DeprecatedMethod` — migrate to
  `upsertAll()`).

## [0.5.0] - 2026-07-18 — Relations, lifecycle & convenience

Additive across the board — no breaking changes.

### Added

- **`RelationType::ManyToMany`** — relate through a pivot (junction) table that holds only the two
  FK columns; `load()`/`loadMissing()` resolve it as a batched two-hop `IN(…)` (pivot query, then
  the targets by PK), returning a `RecordSet` of the related records. Params: `class`, `pivotTable`,
  `pivotLocalKey`, `pivotForeignKey` (`localKey` defaults to the PK). It is deliberately
  **pivot-less** — when the junction carries data, model it as its own Record and traverse it with a
  `OneToMany → ManyToOne` chain for fully-typed pivot columns.
- **`RelationType::HasManyThrough`** — reach the far records via an intermediate Record without
  hydrating it. Params: `class` (far), `through` (intermediate), `foreignKey` (through→local),
  `secondKey` (far→through); `localKey`/`throughKey` default to PKs.
- **Lifecycle hooks** (overridable methods, mirroring the existing `beforeSave()`): `afterSave(bool
  $wasInsert)` fires after an actual write from both `save()` and `saveAll()` (default + chunked),
  never on a clean no-op; `beforeDelete()`/`afterDelete()` around single `delete()` (bulk
  `deleteAll()` bypasses them); `afterLoad()` after every hydration.
- **Auto-timestamps** — `#[CreatedAt]` / `#[UpdatedAt]` on a DateTime/Timestamp column. Both are set
  on INSERT; `UpdatedAt` is additionally bumped on UPDATE. Enforced across `save()`/`saveAll()`
  (bumped only when another column changed) **and** the bulk-UPDATE paths `updateWhere()` /
  `updateByWhere()` / `updateByUniqueKey()`, unless the caller sets the column explicitly. Schema
  validates the column type and one-per-record.
- **find-or-create** — `firstOrNew(array $match, array $defaults = [])` (returns an unsaved
  instance), `findOrCreate(...)` and `updateOrCreate(array $match, array $values)` (both persist).
  Array-match is AND-ed column equality on a non-empty match map.
- **Aggregate finders** — `sumWhere()`, `avgWhere()`, `minWhere()`, `maxWhere()`, `existsWhere()`
  alongside the existing `countWhere()`. Empty `$where` aggregates the whole table; an unknown
  column throws a `SchemaException`.
- **`WhereClause::match(array $match)`** — build an AND-ed all-columns-equal clause from a map
  (values matched as raw scalars — match an enum/VO column by its stored `->value`). Backs
  find-or-create and is usable directly with `find()` / `updateWhere()` / `countWhere()` / etc.

## [0.4.0] - 2026-07-18 — Relation loading, refined

### Added

- **`RecordSet::load()` / `loadMissing()` are variadic and share prefixes.**
  `load('customer.billing', 'customer.shipping.country')` loads `customer` **once**, then descends
  into both branches — via an internal prefix trie, one `IN(…)` query per *distinct* relation level
  (still no N+1, no JOINs).
- **`RecordSet::loadMissing(string ...$paths)`** — the skip-if-already-loaded counterpart to
  `load()`. Load-state is tracked per record (new `Record::relationIsLoaded()`), so a to-one
  relation that legitimately resolved to `null` counts as *loaded* and is not re-queried.
- **`Record::load(...)` / `Record::loadMissing(...)`** — the single-record counterparts, e.g.
  `$order->load('lines', 'customer.billing')` (wrap the record in a one-element set and delegate).

### Changed

- **BREAKING — `RecordSet::with()` renamed to `load()`.** It was always the *imperative post-load*
  loader (it runs immediately against an already-materialised set), whereas Eloquent reserves
  `with()` for *query-time* eager loading — so the old name was a false friend. `with()` stays as a
  **`@deprecated` alias** for `load()` and will be removed at 1.0. Migration is a literal
  `->with(` → `->load(` rename.

## [0.3.0] - 2026-07-18 — Enum column casting

### Added

- **`EnumCaster` — backed-enum ⇆ scalar column.** `#[EnumCaster(MyStatus::class)]` on an
  enum-typed property (`public MyStatus $status`) hydrates to/from the enum's backing value against
  a matching scalar `ColumnType`, so consumers stop hand-rolling `tryFrom` and magic
  ints/strings. The raw DB scalar is normalized to the enum's backing type before `::from()`
  (drivers may return an int column as a numeric string), so int- and string-backed enums both
  round-trip; a non-backed enum is rejected at construction; null short-circuits like every caster.
- **`ENUM` column value sets are derived from `EnumCaster`.** An `Enum` column that carries
  `#[EnumCaster(SomeEnum::class)]` no longer needs an inline `enumValues:` list — `TableSchema`
  derives the `ENUM(...)` set from the enum's cases (via `EnumCaster::enumValues()`), removing a
  duplication that could silently drift out of sync. Supply `enumValues:` only to intentionally
  narrow a column to a subset of the enum's cases; an `Enum` column with neither a caster nor an
  inline list still errors.

### Changed

- **`WhereClause::params()` normalizes PHP booleans to their SQL scalar form** (`true→1`,
  `false→0`). A raw bool has exactly one correct scalar mapping for any column, yet drivers
  disagreed on binding it — interpolating sessions could reject it outright, and PDO's emulated
  prepares bind `false` as an empty string — making `where('active', true)` a latent cross-driver
  footgun. Normalizing at the single `params()` boundary (covering Leaf / IN / IN-tuples / raw /
  between / compound nodes) keeps the bound value symmetric with what a bool column serializes to on
  write, with no column-cast introspection. Non-bool scalars pass through unchanged. **Note:** code
  that asserted on a raw bool in `params()` output now sees the normalized int.

### Documentation

- **`RecordSet::saveAll()` lifecycle documented** — it runs `beforeSave()` / `validate()` per
  record (the full write lifecycle, not a raw `CASE` UPDATE), which is exactly what lets a per-row
  `save()` loop collapse into a single `saveAll()`.

## [0.2.1] - 2026-07-05

### Changed

- **`RetryingDbSession` is no longer `final`.** A consumer whose session is a richer `DbSession`
  subtype can now subclass the decorator to implement the extra interface methods (delegating to its
  own typed inner reference) while inheriting the retry loop verbatim, injecting a domain retry
  policy through the existing `$retryable` seam. No behavioural change; purely relaxes the extension
  point. (Motivating case: an InvFlux `MysqlSession`, which adds `defaultCollation()` to
  `DbSession`, wrapping itself in retry without re-implementing the loop.)

## [0.2.0] - 2026-07-05 — Three backends, built for contention and scale

### Added

- **SQLite as a third first-class backend** — `SqliteDialect` (DDL emission, batch insert/upsert,
  advisory-lock no-ops). Requires **SQLite ≥ 3.33** (2020-08) for the `UPDATE … FROM` join used by
  bulk upserts. Integration suites run against MySQL/MariaDB, PostgreSQL, **and** SQLite.
- **Connection hardening** — `SqlDialect::connectionInitStatements()` returns per-connection setup
  statements that `Connection` runs on construct. `SqliteDialect` emits `journal_mode` (WAL by
  default), `busy_timeout`, and `foreign_keys` pragmas (all configurable via its constructor).
- **`RetryingDbSession`** — an opt-in `DbSession` decorator that retries the **outer** transaction on
  transient conflicts (deadlock / lock-wait timeout / serialization failure / `SQLITE_BUSY`) with
  exponential backoff + jitter. Prunable and composable; wrap a session only where you want retries.
- **Chunked `RecordSet::saveAll()`** — `saveAll(bool $force = false, ?int $chunkSize = null, bool
  $allowInTransactionChunking = false)`. With a `$chunkSize`, the write is split into slices that
  **commit independently**, bounding the lock/undo footprint for very large batches (not
  all-or-nothing — resumable via dirty-tracking). Default (`null`) is unchanged: one atomic
  transaction. `$allowInTransactionChunking` opts into chunked-but-atomic when nested in an outer
  transaction; without it, a chunked call inside a transaction throws rather than silently degrade.

### Changed

- **Bulk-upsert UPDATE rewritten from per-column `CASE` to a single multi-mask derived-table join**
  (`UpsertJoinBuilder`): `O(N²·M) → O(N·M)`. Per-row column selectivity travels as a per-row integer
  bitmask (`_m0, _m1, …`, 63 bits each); columns changed by every row are written directly, so a
  homogeneous batch carries no mask. One uniform path for any column count — the `buildUpsertCaseSet`
  helper is removed.
- **`DbSession` gained `isRetryableTransactionError(\Throwable): bool`** — the transient-error
  classifier used by `RetryingDbSession`, folded in from a separate interface. **Breaking for custom
  `DbSession` implementations**, which must now implement it (all bundled sessions do).
- **`SqlDialect` gained `forUpdateClause()` and `connectionInitStatements()`**, and
  `buildUpsertSql()` gained a trailing `array $rowDirtyColumns = []` parameter. `FOR UPDATE` is now
  dialect-gated (SQLite, which serializes writers, omits it). **Breaking for custom `SqlDialect`
  implementations.**

## [0.1.3] - 2026-07-05

### Fixed

- `RecordSet::saveAll()` no longer clobbers a column that one record in a batch changed but another
  did not. Previously every record wrote the batch-wide union of changed columns using its own
  in-memory value, so a **heterogeneous batch of partially-populated keyed records** — each carrying
  a different subset of fields (the natural controller shape) — would overwrite, on the records that
  did not send a given column, that column with their default (e.g. `NULL`). `saveAll()` is now
  dirty-scoped **per row**, matching single-row `save()`: the upsert's `CASE` writes each column only
  for the rows that actually changed it, and rows that did not keep their live value.

### Changed

- `SqlDialect::buildUpsertSql()` gained a trailing `array $rowDirtyColumns = []` parameter carrying,
  per row, the set of columns that row changed. Defaulted, so existing callers are unaffected; when
  empty, every row participates in every column (the prior behaviour). Custom `SqlDialect`
  implementations that override `buildUpsertSql()` should accept and honour the new parameter to get
  the per-row dirty scoping (a column changed by every row still emits the plain all-rows `CASE`;
  a column changed by only some rows emits `… ELSE <col> END`).

## [0.1.2] - 2026-07-05

### Fixed

- `RecordSet::saveAll()` now persists a nullable column that is **cleared back to `NULL`** on a
  keyed record. The deadlock-safe upsert's `CASE`-update column list previously included a column
  only when it held a non-null value on some record, so a value set to `null` was absent from the
  `CASE` and the old (non-null) value silently survived. The column is now included whenever it is
  **dirty** on any record.
- `PgsqlDialect::toLiteral()` now emits a **typed** null (`CAST(NULL AS <type>)`) instead of a bare
  `NULL` for non-autoincrement columns. A bare `NULL` is untyped; PostgreSQL defaults it to `text`
  inside the upsert's `CASE … THEN NULL END` branch and rejects it against a non-text column
  (`SQLSTATE 42804`). Autoincrement (`SERIAL`) columns — which render null only in `INSERT VALUES`,
  never in a `CASE`, and whose pseudo-types are not castable — stay bare. (Required for the
  null-clearing fix above to work on PostgreSQL.)

## [0.1.1] - 2026-07-01

### Fixed

- `MysqliDbSession::isDuplicateKeyError()` now detects duplicate-key violations via the thrown
  `mysqli_sql_exception`'s error code (`getCode()`), not `$conn->errno` — the latter is not
  reliably populated after a prepared-statement failure across MySQL/MariaDB versions, causing
  false negatives on some servers.

## [0.1.0] - 2026-06-30

Initial public release.

### Added

- **Attribute-driven Records** — declare schema with `#[Table]`, `#[Column]`, `#[Relation]`,
  `#[UniqueKey]`, `#[Index]`, `#[ForeignKey]`, `#[LockTier]` attributes; no XML/YAML/migrations.
- **Dirty tracking** — `save()` writes only changed columns.
- **Finders** — `getOne`/`find`/`findOne`/`where`/`whereIn`/`whereInTuples`/`countWhere`, plus the
  immutable `WhereClause` builder and `RawSql` escape hatch.
- **RecordSet** — single-statement batch `saveAll()` (bulk insert + deadlock-safe upsert),
  `deleteAll()`, and N+1-free eager loading via `with()` (including dot-paths and polymorphic
  relations).
- **Burn-free upserts** — `upsertByUniqueKey(..., preserveAutoIncrement: true)` and
  `RecordSet::upsertAllByUniqueKey()`; plus `updateByUniqueKey` / `updateByWhere`.
- **Column casting** — `#[Cast]` family (`DateTimeCaster`, `EpochCaster`, `JsonCaster`) and the
  `JsonCastable` interface.
- **Validation** — `validate()` hook enforced at assignment and save time.
- **Deadlock-safe locking** — `LockTier` / `LockSet` / `Transaction`, plus connection-scoped
  advisory locks.
- **`CREATE TABLE` DDL generation** from the same attributes — for MySQL/MariaDB **and**
  PostgreSQL.
- **DbSession adapters** — PDO, mysqli, and WordPress `wpdb`, behind one `DbSession` contract.
- **Application-minted binary primary keys** (`BINARY(16)` / `BYTEA` UUIDs), bound correctly on
  both engines.

[Unreleased]: https://github.com/Nandan108/attrecord/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/Nandan108/attrecord/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Nandan108/attrecord/releases/tag/v0.1.0
