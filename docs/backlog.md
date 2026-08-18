# attrecord — backlog

Deferred features, captured so the decisions aren't lost. **Implemented items are removed from
this file** — shipped behaviour is recorded in the code and the CHANGELOG, which is where a reader
looks for what attrecord *does*. A backlog that also archives conclusions stops being a list of
work and starts being a second, staler set of docs.

## DDL features not yet modelled by the producer

The DDL producer ([ddl-generation.md](ddl-generation.md)) emits columns, defaults, generated
columns, primary/unique keys, indexes, foreign keys and CHECK constraints. What it does **not**
model, all surfaced while evaluating a "single source of DDL" move for a consumer (InvFlux):

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

## Prefix-scoped index names on PostgreSQL — *deferred, no consumer*

0.16.0 made **several prefixed installs in one database** work on MySQL/MariaDB by putting a digest
of the table prefix into foreign-key constraint names. The same configuration still fails on
**PostgreSQL**, for a different identifier: index and unique-key names live in the schema-wide
relation namespace there, so a second install collides on `relation "uniq_sku" already exists`
before any constraint name is reached. Scoping table in
[ddl-generation.md](ddl-generation.md#fk-constraint-naming--and-why-several-installs-may-share-one-database).

**If built, prefix — don't hash.** The digest exists to solve a *length* problem: an FK name already
embeds a long table name, so a long or hardened prefix added verbatim overflowed the identifier
limit. An index name is short and user-chosen, so `wp_uniq_sku` fits comfortably inside PostgreSQL's
63 and stays readable in an error message. Hashing there would cost legibility for nothing.

**It is runtime-transparent, which makes it cheaper than it looks.** `upsertByUniqueKey('uniq_sku')`
resolves the declared name to *columns* (`$schema->uniqueKeys[$conflictKey]`), and both
`ON CONFLICT (cols)` and `ON DUPLICATE KEY UPDATE` name columns rather than the index — so renaming
the physical index breaks nothing attrecord does. The costs are raw SQL that names an index, index
hints, and mangled names in engine error messages.

**Why it is deferred.** The only beneficiary is *two installs in one PostgreSQL schema*, and
PostgreSQL already answers that better: a **schema per install** isolates everything rather than just
names, costs nothing, and is what the docs recommend. Building name-mangling for a configuration we
steer people away from is effort pointed the wrong way — and it would be another breaking change to
every PostgreSQL install's physical schema. No consumer can reach it either: InvFlux is MySQL-only,
as is its PrestaShop adapter.

**Trigger to revisit:** a consumer needing prefix-based multi-tenancy on PostgreSQL — a hosting
product that cannot grant schema creation, for instance. Unlike the FK case this only touches index
and unique-key emission, and it should be PostgreSQL-only: MySQL scopes index names per table, so
prefixing them there would mangle names for no benefit.
