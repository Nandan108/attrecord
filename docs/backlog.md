# attrecord — backlog

Deferred features, captured so the decisions aren't lost. **Implemented items are removed from
this file** — shipped behaviour is recorded in the code and the CHANGELOG, which is where a reader
looks for what attrecord *does*. A backlog that also archives conclusions stops being a list of
work and starts being a second, staler set of docs.

## DDL features not yet modelled by the producer

The DDL producer ([ddl-generation.md](ddl-generation.md)) emits columns, defaults, generated
columns, primary/unique keys, indexes, and foreign keys. What it does **not** model, all surfaced
while evaluating a "single source of DDL" move for a consumer (InvFlux):

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

### Composite primary keys, DDL-only — *wanted by a real consumer, contained*

`#[Table(primaryKey:)]` takes a single **column name**, so `PRIMARY KEY (subject_id, slot_id)`
cannot be declared at all. Every junction table, and every "one row per (a, b)" state table, is
therefore outside what a Record can describe.

Making the *whole* runtime composite-key-aware is a large change — `save()`, `find()`, the
relation loader, `LockSet`'s ascending-PK ordering and `RecordSet`'s keyed upsert all assume a
single PK column. But the consumer need is narrower than that:

- **DDL-only is enough.** A `DdlOnlyRecord` exists to declare a table whose R/W stays raw SQL. For
  those, only `buildCreateTable` has to understand a composite key; the CRUD paths never run.
  Something like `#[PrimaryKey(columns: ['subject_id', 'slot_id'])]` (class-level, mutually
  exclusive with `#[Table(primaryKey:)]`), with the runtime CRUD refusing such a Record outright
  rather than half-supporting it.
- **The consumer:** InvFlux's `invflux_inventory_state` is keyed `(subject_id, slot_id)` and is
  the one table left outside its managed schema purely for this reason — it hand-writes DDL that
  the differ then cannot see. `attrecord-migrations` would need a matching comparison path (it
  currently classifies any composite/changed PK as Manual, which is safe but means such a table
  would report drift forever if declared).
- **Status:** deferred 2026-07-27 while dogfooding convergence into InvFlux; safe to defer there
  because nothing references that table, so it has no creation-order constraint.
