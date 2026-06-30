# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Nandan108/attrecord/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Nandan108/attrecord/releases/tag/v0.1.0
