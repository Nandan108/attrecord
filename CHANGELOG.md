# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
