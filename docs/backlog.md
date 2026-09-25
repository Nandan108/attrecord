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

---

## A SQL fragment vocabulary on `SqlDialect` — *deferred, it is PostgreSQL work*

A dozen or so expression-level methods, each rendered per dialect, so hand-written SQL composes
portable fragments instead of MySQL-only constructs: `nullSafeEquals`, `greatest`/`least`,
`castToInt`, `nowWithFraction`, `dateAdd`, `groupConcat`, `regexpMatch`, `concat`, `ifNull`. Plain
string in, plain string out, so a fragment drops into a raw `WhereClause` predicate
(`"WHERE {$d->nullSafeEquals('a','b')}"`), into `generatedAs`, and into raw SQL, with no query
builder anywhere. Designed 2026-09-24 against a real count — roughly 200 MySQL-only sites across a
consumer's three repositories.

**Why it is deferred.** The consumer that asked for it (InvFlux, running under WordPress Playground's
SQLite translator) stays on `MysqlDialect`, because the translator only knows tables it created
itself. So the fragments would always render their MySQL form there and buy nothing. The vocabulary
is therefore **PostgreSQL work wearing a SQLite hat**, and it waits for that trigger. The handful of
constructs the translator actually rejects were rewritten consumer-side in the subset both engines
accept — a null-safe comparison spelled out longhand, `SELECT … FOR UPDATE` then `UPDATE` in place of
`LAST_INSERT_ID(expr)` as a counter, `INSERT IGNORE` then a unique-key lookup for interning.

**Trigger to revisit:** a consumer actually running on PostgreSQL, or a second engine reached through
something other than a translator.

**The rule it has to follow, if it is ever built — a fragment promises semantics, not syntax.**
Rendering each engine's local spelling is the easy half and the wrong target. Measured 2026-09-24:

| Expression | MariaDB | SQLite 3.51 | PostgreSQL 16 |
|---|---|---|---|
| `GREATEST(1, NULL)` / `max(1, NULL)` | `NULL` | `NULL` | **`1`** |

PostgreSQL's `GREATEST` *ignores* NULLs where MySQL's and SQLite's propagate them. So the obvious
arrangement — `GREATEST` on MySQL and PostgreSQL, `max` on SQLite — is the one that is wrong, and it
is wrong on the engine whose spelling matched. Same rows, different number, no error. Three
consequences: each fragment states its NULL behaviour and every dialect meets it; a fragment an
engine cannot honour **throws at render time naming itself** rather than degrading to something close
(`regexpMatch` on SQLite, which has no regex without a registered UDF; `castToInt` offers signed only,
since `UNSIGNED` has no counterpart elsewhere); and every fragment is tested for the same **value**
on three engines, never the same string — a string-equality test would have passed the `GREATEST`
bug.

**One thing this would close, and a gap worth knowing about meanwhile.** `generatedAs` is a raw
string emitted verbatim by all three dialects, so portability there is entirely the author's problem.
Our own tri-backend matrix does not test that: every fixture is a hand-picked portable expression
(`COALESCE(x, 0)`), so the suite has never once run a non-portable generation expression. That is
coverage which looks real and is not.
