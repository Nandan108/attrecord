# attrecord — backlog

Deferred / potential features. Nothing here is currently needed by a consumer; captured so
the decisions aren't lost.

## Migration system — *biggest missing feature; deferred by design*

Today the DDL producer emits **fresh-install** `CREATE TABLE` only. There is no schema diffing,
no `ALTER TABLE` generation, and no migration tracking/versioning. For evolving a live schema,
a consumer currently hand-writes migrations (or regenerates and diffs manually).

A first-class migration system is the largest feature gap. Rough shape if/when built:

- **Schema diff** — compare the attribute-derived `TableSchema` for a Record against the live
  table (via `information_schema` introspection) and emit the `ALTER TABLE` delta:
  add/drop/modify columns, add/drop indexes & unique keys, add/drop FK constraints.
- **Migration tracking** — a `migrations` ledger table + an ordered, idempotent apply/rollback
  runner (forward diffs are mechanical; safe down-migrations are the hard part).
- **Dialect-aware** — both MySQL/MariaDB and PostgreSQL, mirroring the dual-dialect DDL producer.

**Why deferred for 0.1.0:** diffing + reversible migrations is a large, correctness-sensitive
subsystem (destructive `ALTER`s, data-preserving column changes, online-DDL concerns) that is
better designed against real evolution needs than speculatively. The fresh-install producer
already covers install/bootstrap, which is what consumers need first. Likely a separate,
opt-in companion package rather than core, to keep attrecord dependency-free and small.

- **Status:** acknowledged as the top roadmap item; not yet scheduled.

## DDL features not yet modelled by the producer

The DDL producer ([ddl-generation.md](ddl-generation.md)) currently emits columns, defaults,
generated columns, primary/unique keys, indexes, and foreign keys. Three things it does
**not** model came up while evaluating a "single source of DDL" move for a consumer
(InvFlux). All three are deferred:

### `#[Check]` — CHECK constraints — *small, low priority*

Declarative row/column invariant, e.g. `CHECK (quantity >= 0)` or `CHECK (status IN (…))`,
emitted into `CREATE TABLE`. Cheap to add (one more clause in `buildCreateTable`).

- **Value:** defense-in-depth — a DB-level invariant that holds even against a buggy or
  raw-SQL write path (e.g. a non-negative-quantity backstop on an inventory balances table).
- **Caveat to document if built:** enforcement is engine/version-dependent — MySQL **8.0.16+**
  enforces, MySQL **5.7 parses and silently ignores**, MariaDB enforces from **10.2.1+**. So a
  consumer that can't pin the host DB version (e.g. a WordPress plugin) must treat it as
  supplementary, never the sole guard.
- **Status:** nice-to-have; not requested by any consumer yet.

### Partitioning — *deferred, heavy, design-against-a-real-table*

`PARTITION BY RANGE/HASH/LIST (…)`, primarily for append-only ever-growing ledgers: query
pruning + near-free retention via `DROP PARTITION` (vs. expensive `DELETE`).

- **Why it's heavy, not a flag:** MySQL does **not** allow foreign keys on partitioned InnoDB
  tables, and the partition column must be part of **every** unique/primary key. So the
  producer would have to suppress FK emission and recompose PKs on partitioned tables — an
  abstraction best designed against a concrete first table when one actually needs it, not
  speculatively.
- **Status:** revisit only when a consumer's ledger row count forces it; at that point it's as
  much a domain decision (drop FKs? retention window?) as an attrecord feature.

### FULLTEXT indexes — *future, no current need*

`FULLTEXT KEY (…)` for natural-language search columns. No consumer need on the horizon;
captured only for completeness.
