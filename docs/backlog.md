# attrecord — backlog

Deferred / potential features. Nothing here is currently needed by a consumer; captured so
the decisions aren't lost.

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
