# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
