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

---

## `#[JsonCaster(sortKeys: true)]` — canonical key order on the preserving backends

**Agreed 2026-08-07; designed, not built.** Opt-in recursive key sorting applied on **write**.

`ColumnType::Json` maps to engine types that disagree about whether object key order survives a
round trip — the table and the rule live in
[column-casting.md](column-casting.md#json-portability--object-key-order-is-not-preserved-everywhere).
MySQL (binary `JSON`) and PostgreSQL (`JSONB`) normalize; MariaDB (`LONGTEXT`) and SQLite (`TEXT`)
store the bytes verbatim. Because MySQL and PG also compare JSON *semantically*, `GROUP BY` /
`DISTINCT` / a unique index over such a column already behave correctly there. **On MariaDB and
SQLite they do not**: the same logical payload written with keys in two orders is two distinct
strings, so it forms two groups. That is the problem this solves.

Settled design:

- **`#[JsonCaster(sortKeys: false)]`** — new constructor arg, default off. Opt-in, so nothing pays a
  recursive walk for a property that doesn't need it, and it is additive/BC.
- **Applied in `toDb()`, never `fromDb()`.** Grouping, hashing and unique indexes act on stored
  bytes; a read-side sort cannot repair bytes the server already holds.
- **Ordering must match the native one: key length ascending, then bytewise** — *not* `ksort()`,
  which is lexicographic and would make MariaDB disagree with the engines it is meant to imitate.
  Verified on PostgreSQL 16: `{"bb":1,"aa":2,"c":3,"dddd":4,"ab":5}` stores as
  `{"c":3,"aa":2,"ab":5,"bb":1,"dddd":4}`.
- **No-op on MySQL / PostgreSQL.** Semantically it already is — the engine renormalizes to the same
  order, so sorting is redundant work. *Open question:* `ColumnCaster::toDb(mixed $value,
  ColumnDefinition $col)` receives **no dialect**, so skipping the work per-dialect is not directly
  expressible. Either the caster consults the ambient `Record::connection()->dialect` (feasible, and
  it respects `usingConnection()` scoping, but couples a caster to ambient state), or the interface
  grows dialect awareness (a **breaking** interface change, same class as 0.11.0/0.12.0). Decide
  before implementing.
- **Recursive, objects only.** Descend into lists but never reorder them — gate on `array_is_list()`.

Two things to **document when built**:

- **It is not retroactive.** Rows written before it keep their original key order and stay in
  separate groups until rewritten. Enabling it on live data implies a rewrite migration — the kind
  of thing discovered only after an aggregate reports wrong numbers.
- **`sortKeys` is not "canonical JSON".** Key order is one input to byte-equality alongside encode
  flags (`UNESCAPED_UNICODE`/`UNESCAPED_SLASHES`), float formatting, unicode escaping and duplicate
  keys. The narrow name is deliberate — it keeps the promise the size of the feature.

For grouping specifically, a hash or generated column over the canonical form is usually a better
design than `GROUP BY` on the payload (indexable; no filesort over a `LONGTEXT`) — but it needs the
same canonical encoding, so the requirement lands here either way.

**Status:** agreed with the maintainer, unbuilt. Additive → a minor (`0.16.0`); consumers pinned
`^0.15` would each need a floor bump, verified across the span per the consumer's release rule.
