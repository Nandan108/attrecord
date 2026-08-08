# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **! Foreign-key constraint names now carry a digest of the table prefix.** Previously the prefix
  was *stripped* from the name, which made two installs sharing one database collide: InnoDB scopes
  constraint names **per database, not per table**, so `wp_invflux_subject_identifiers` and
  `ps_invflux_subject_identifiers` both wanted `fk_invflux_subject_identifiers_subject_id` and the
  second `CREATE TABLE` died with errno 121, *"duplicate key on write or update"*.

  Two configurations hit it: a PrestaShop→WooCommerce cutover running both hosts against one
  database, and two WordPress sites at `wp_` and `blog_` on shared hosting. WordPress multisite
  escaped by luck — `wp_2_` left `2_` behind after stripping — so it would not show up in multisite
  testing.

  ```
  before   fk_invflux_subject_identifiers_subject_id          (identical for every prefix)
  after    fk_ec2dc5_invflux_subject_identifiers_subject_id   (wp_)
           fk_2f9391_invflux_subject_identifiers_subject_id   (ps_)
  ```

  The digest is the first 6 hex of `sha256(prefix)` — collision avoidance between installs on one
  machine, not a cryptographic claim. It is **fixed-width on purpose**: simply *not* stripping would
  have made the name grow with the prefix and overflow the 64-character identifier limit for a long
  or hardened prefix. An unprefixed install gets no digest and keeps a fully readable
  `fk_<table>_<column>`.

  The prefix is now taken from `Record::tablePrefix()` and removed exactly, rather than guessed with
  a `/^[a-z0-9]+_/` regex — that guess was the defect, and it also mangled unprefixed tables
  (`attrecord_ddl_orders` → `fk_ddl_orders_…`, eating a real part of the name).

  **Long names are folded rather than truncated blindly.** `fk_ + digest + table + column` still
  overflows on real schemas — InvFlux's
  `invflux_subject_stock_management_events.reconciliation_run_id` reaches 71 — so past the limit the
  *column* becomes a 10-hex digest and the table name is kept, that being the more useful half in an
  error message. The result is deterministic and provably ≤ 64 for any input.

  **Why this is marked breaking.** Existing databases carry the old names, so a converged install
  sees FK-name drift. It is safe to leave: the collision only occurs at `CREATE TABLE`, and a
  database that already holds an install has by definition not collided. `attrecord-migrations`
  classifies `drop_foreign_key` as `Destructive`, so at the default `Safe` ceiling the rename is
  *reported and not applied* — existing tables keep their names unless a Destructive-ceiling run is
  made deliberately.

### Added

- **`#[JsonCaster(sortKeys: true)]` — canonical object key order on the backends that don't
  normalize.** Opt-in, off by default, applied on write.

  `ColumnType::Json` maps to engine types that disagree about whether an object's key order
  survives a round trip: MySQL (binary `JSON`) and PostgreSQL (`JSONB`) normalize it, MariaDB
  (`LONGTEXT`) and SQLite (`TEXT`) store the bytes verbatim. MySQL and PG also *compare* JSON
  semantically, so `GROUP BY` / `DISTINCT` / a unique index over such a column already behaves
  correctly there. **On MariaDB and SQLite it does not** — the same logical payload written with
  keys in two orders is two different strings, hence two groups. That is what this fixes.

  ```php
  #[Column(ColumnType::Json)]
  #[JsonCaster(sortKeys: true)]
  public array $payload = [];
  ```

  The ordering is **key length ascending, then bytewise** — the rule the normalizing engines
  apply internally, so a MariaDB row now matches what MySQL/PG would have stored. Deliberately
  **not** `ksort()`: lexicographic order would make MariaDB disagree with the very engines it is
  imitating. Verified against PostgreSQL 16, whose `jsonb_object_keys` returns `c, aa, ab, bb,
  dddd` for `{"bb":1,"aa":2,"c":3,"dddd":4,"ab":5}` — exactly what the caster now emits.

  Applied in `toDb()` only: grouping and hashing act on stored bytes, and the read side cannot
  repair bytes the server already holds. Applied on every dialect, including the ones that would
  have normalized anyway — the result is identical, and a caster whose output varied with the
  ambient dialect would stop being the pure, stateless mapping the rest of the contract assumes
  (`ColumnCaster::toDb()` receives no dialect, and most of its 27 call sites have none to give).

  Lists are recursed into but never reordered; `stdClass` stays `stdClass`, so an empty object
  keeps encoding as `{}` instead of collapsing to `[]`.

  **Not retroactive** — rows written before it was enabled keep their original order and go on
  forming their own groups until rewritten; enabling it on live data implies a rewrite migration.
  And it is **not "canonical JSON"**: key order is one input to byte-equality alongside encode
  flags, float formatting, unicode escaping and duplicate keys. The narrow name is deliberate.

## [0.15.0] - 2026-08-06

One feature, backward compatible — the `default:` parameter type is widened, not changed. It reads
better, and on PHP 8.1 it is the only form that works at all.

### Added

- **`#[Column(default: …)]` accepts a backed enum case.** `default: Status::Active` now works
  alongside `default: 'active'`. The case is unwrapped to its backing value at the single point
  where the attribute becomes a `ColumnDefinition`, so `ColumnDefinition`, every dialect and the DDL
  producer keep dealing in scalars only — the emitted SQL is byte-identical to the literal form.

  ```php
  #[Column(ColumnType::Enum, enumValues: ['draft', 'active'], default: Status::Active)]
  public string $status = 'active';
  ```

  Two reasons, and the second is the urgent one.

  It reads better: the attribute names the vocabulary that owns the value instead of restating it,
  so a renumbered case cannot leave a stale default behind.

  More importantly, **it is the only form available below PHP 8.2.** The natural workaround,
  `default: Status::Active->value`, is a property fetch, and property fetches are not valid constant
  expressions before 8.2. Written in an attribute it does not degrade gracefully: the whole class
  becomes unparseable and the process dies with *"Constant expression contains invalid operations"*
  the moment it autoloads. Because the failure is confined to 8.1, a package declaring `php ^8.1`
  can ship that false claim for months while every developer machine runs 8.3 — which is exactly
  how a downstream package found it.

## [0.14.1] - 2026-08-03

One fix. A write path that quietly skipped the auto-managed timestamps, which on a `NOT NULL`
timestamp column is not a wrong value but a failed write.

### Fixed

- **`Record::upsertByUniqueKey()` now honours `#[CreatedAt]` / `#[UpdatedAt]`.** It built its INSERT
  parameters straight off the properties without ever stamping the auto-managed timestamps — the one
  write path that skipped them, where `save()` and `RecordSet::upsertAll()` both call
  `applyAutoTimestamps()`. Because attrecord writes *every* non-generated column, an unstamped
  property bound an explicit `NULL`, and an explicit `NULL` defeats a `DEFAULT CURRENT_TIMESTAMP`
  column default. On a `NOT NULL` timestamp column that is not a wrong value but a hard write
  failure, so the first upsert of any new key threw.

  Both variants are covered, each as precisely as it can be:

  - The **atomic** path (`INSERT … ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE`) cannot know
    which branch the database will take. `#[UpdatedAt]` is stamped and mirrored into the conflict
    SET — unless the caller drives that column themselves, matching how `updateWhere()` fills only
    a gap — so the row gets a fresh value either way. `#[CreatedAt]` is stamped **only when the
    property is null**, so a value the caller supplied (importing historical rows) survives; it is
    never added to the SET, so a conflicting write leaves the stored one alone.
  - The **burn-free** path (`preserveAutoIncrement: true`) resolves the row first and so knows its
    branch exactly: `#[CreatedAt]` and `#[UpdatedAt]` on the INSERT, `#[UpdatedAt]` alone on the
    UPDATE, with `#[CreatedAt]` left untouched on the row *and* on the object.

  A blind upsert bumps `#[UpdatedAt]` unconditionally: with no loaded row to diff against there is
  no way to tell a no-op update from a real one, which is already how the set-based UPDATE paths
  behave. `RecordSet::upsertAllByUniqueKey()` was never affected — it resolves PKs and delegates to
  `upsertAll()`, which stamps correctly.

## [0.14.0] - 2026-07-29

One feature, shipped narrow on purpose. A table keyed on two columns could not be declared at all,
so its DDL had to be hand-written — and hand-written DDL is invisible to schema-evolution tooling,
which compares a live database against *declared* schemas. Such tables sat outside the managed set
and drifted unobserved. They can now be described, and only described: every CRUD path refuses
them, because half-supporting a composite key is worse than not supporting one.

### Added

- **`#[PrimaryKey(columns: [...])]` — composite primary keys, DDL-only.** A table keyed
  `(subject_id, slot_id)` could not be declared at all: `#[Table(primaryKey:)]` takes one column
  name. Every junction table, and every "one row per (a, b)" state table, was therefore outside
  what a Record could describe — so its DDL had to be hand-written, and hand-written DDL is
  invisible to `attrecord-migrations`, which compares the live database against *declared*
  schemas. Such a table silently sat outside the managed set and drifted unobserved.

  ```php
  #[Table(name: 'inventory_state')]
  #[PrimaryKey(columns: ['subject_id', 'slot_id'])]
  final class InventoryStateRecord extends Record { ... }
  ```

  **DDL-only, and enforced rather than merely documented.** `save()`, `delete()`,
  `upsertByUniqueKey()`, the `RecordSet` bulk writers, relation loading and `LockSet::acquire()`
  all throw on such a Record, naming the operation and the key. Half-support would be worse than
  none: `$pk` holds only the first member, so a keyed upsert would target the wrong row and
  `LockSet`'s ascending-PK ordering would be neither total nor the ordering the table's other
  access paths use — and two orderings of one table is precisely the deadlock `LockSet` exists to
  prevent. Reads are *not* blocked, a `WHERE`-based `SELECT` needing no primary key.

  Making the whole runtime composite-key-aware is a much larger change (`find()`, the relation
  loader, the keyed upsert, lock ordering); this is the contained version that solves the actual
  consumer need — describing the table so tooling can see it — without pretending to the rest.

  Validation refuses what would be silently wrong: fewer than two columns (that is
  `#[Table(primaryKey:)]` spelled longer), a repeated member, a member that is not a declared
  column, an auto-increment member (no engine allows one in a composite key), and declaring both
  PK forms at once (a contradiction, not an override).

  `TableSchema::pkColumns()` returns the whole key on any schema — one entry for an ordinary
  table — and is what the three dialects now emit from.

### Fixed

- **Doc references in shipped source no longer dangle.** Five `@see docs/…` comments used paths
  relative to a `docs/` directory that is `export-ignore`d, so inside a consumer's `vendor/` they
  pointed at nothing. They are absolute GitHub URLs now, matching what `attrecord-migrations`
  already did.


## [0.13.0] - 2026-07-29

Four places where the code and its own contract disagreed, three of them found by consumers rather
than by the test suite. `set()` documented that it ignored unknown keys and instead created dynamic
properties; `LockSet::acquire()` asked for a session and then reached past it to a global for the
dialect it actually needed; `$ignoreColumns` could not express the one case a caller had; and the
enum CHECK constraint stated its members in a form nothing could read back. Each fix makes the
declared surface and the behaviour agree, and the first two are **breaking** — pre-1.0, marked, and
mechanical to migrate.

### Changed

- **`Record::set()` now throws on a key that is not a column property** — *breaking*. The docblock
  said unknown keys were silently ignored; the loop assigned them regardless. `Record` declares no
  `__set` and no `#[AllowDynamicProperties]`, so a typo'd key created a **dynamic property**: the
  write appeared to succeed, the value was retrievable, and no column ever read it — the exact
  failure the contract existed to prevent. It was also an upgrade blocker, dynamic property
  creation being deprecated on PHP 8.2 and an `Error` on PHP 9, so every caller passing a superset
  array (a request payload, a CSV row, a fixture with extra keys) was carrying one.

  The exception names the key, the class and the known properties. Keys are *property* names, which
  differ from column names wherever a column declares a `name:` override, so the check runs against
  the new memoized `TableSchema::columnProperties()` rather than the column map. `newWith()`
  delegates to `set()` and inherits the behaviour.

  Filtering to declared properties — making the code match the old docblock — was the alternative,
  and was rejected: silently dropping a typo'd key is strictly worse than rejecting it. Callers
  that deliberately pass a superset must now filter at the call site.

- **`LockSet::acquire()` takes a `Connection`, not a `DbSession`** — *breaking*. It does not merely
  execute SQL, it **generates** it: quoting the table and PK, and asking whether the backend has a
  `FOR UPDATE` clause at all. A `DbSession` can answer neither; a `Connection` is exactly a session
  plus the dialect that can. The old signature therefore could not be satisfied by its own
  parameter, and closed the gap by reading `$class::connection()->dialect` — so a caller that
  passed a session *without* also having configured a global connection got
  `No Connection configured`, and a caller whose global pointed at a different backend than the
  session got SQL quoted for the wrong one.

  Fixing this by taking the dialect from the session was considered and rejected: a session is a
  statement executor, and decorators like `RetryingDbSession` have no dialect of their own to give.
  Adding an optional `?SqlDialect` argument was too — it makes callers pass two objects that are
  only meaningful together, which is what `Connection` already is.

  Migration is mechanical, and most call sites hold a `Connection` already and were reaching into
  `->session` to call this:

  ```diff
  - LockSet::acquire($connection->session, [Foo::class => $ids], $tx);
  + LockSet::acquire($connection, [Foo::class => $ids], $tx);
  ```

  Note that `usingSession()` borrows the ambient dialect the same way, which is now documented on
  the method as a deliberate limitation rather than left as an implementation detail — prefer
  `usingConnection()` wherever the dialect matters.

  Reported against 0.12.0 by a consumer whose lock-ordering unit tests pass a fake session and
  deliberately configure no global connection. Regression from `c3bb1c5` / `ca11f67`: before
  `FOR UPDATE` was gated behind the dialect, the SQL was hard-coded MySQL and no dialect was needed.

- **The enum CHECK constraint is now named** `chk_<column>_enum` on PostgreSQL and SQLite, which
  emit an enum as `TEXT` plus a CHECK rather than a native type. It was anonymous, which made the
  member list **write-only**: PostgreSQL rewrites the body (`col IN ('a','b')` comes back as
  `col = ANY (ARRAY['a'::text, 'b'::text])`) but never the name, so with no name there was no
  stable handle on which constraint carried the members — and schema tooling could not read them
  back to notice that a PHP enum had gained a case the database still rejects.

  Column-scoped rather than table-scoped because CHECK constraint names are unique per *table* on
  both engines (unlike index-backed UNIQUE constraints, which are schema-scoped) — verified against
  PostgreSQL 16. That is what lets `buildColumnLine()`, which never sees a table name, emit it.
  Exposed as `ColumnDefinition::enumCheckConstraintName()` so consumers share one definition of the
  convention instead of hard-coding it.

  Affects emitted DDL on those two dialects only; MySQL-family is untouched, its members living in
  the column type. `attrecord-migrations` >= 0.3 uses this to close the corresponding blind spot.

### Added

- **`$ignoreColumns` can drop a column from the UPDATE only**, on `save()`, `upsertAll()` and its
  chunked and lockless paths. It used to act symmetrically — out of the INSERT (letting the DB
  default fire) *and* out of the UPDATE `SET`. The asymmetric case had no expression at all: write
  a value on insert, then never overwrite it. A flat list cannot say it, because dropping the
  column from the insert means a new row never receives the value; dirty tracking cannot either,
  since such a column is legitimately dirty from having just been computed.

  ```php
  ignoreColumns: ['status']                     // both phases — unchanged
  ignoreColumns: ['update' => ['parent_id']]    // inserted once, then protected
  ```

  The two shapes are told apart by key type (a list has integer keys), so a column literally named
  `update` is never ambiguous. `save()` needs no phase resolution beyond picking the set — one save
  is an insert or an update, never both.

  There is deliberately **no `insert` phase**, for a structural reason rather than a scope cut: in
  the deadlock-safe keyed upsert the UPDATE step reads each value from the row the INSERT step
  wrote, so a column absent from the insert cannot be an update target. The sets can diverge in one
  direction only, and an `insert` key would therefore mean different things per strategy. A flat
  list already covers "drop it from both".

  An include-list (name the columns to write) was considered and rejected: an INSERT must emit a
  complete row, so naming a subset yields not a partial write but a row carrying PHP property
  defaults in every unnamed column. On the update side it is largely redundant — dirty tracking
  already scopes the `SET`.

- **`Record::clearConnections()`** — forget the default and all per-class connections. Test
  support: asserting that a component works on the connection it was *given* is an assertion only a
  suite with no global connection can make, since otherwise the global quietly satisfies an
  accidental `connection()` call and the test passes while the coupling survives. Does not touch a
  `usingConnection()` scope, which belongs to the block that opened it.

## [0.12.0] - 2026-07-27

Two seams for schema-evolution tooling, both driven by a real consumer
([attrecord-migrations](https://github.com/Nandan108/attrecord-migrations) converging InvFlux's
schema): describing a table whose shape is only known at runtime, and creating one whose foreign
keys form a cycle.

### Added

- **`TableSchema::extendedWith(columns:, indexes:, uniqueKeys:)`** — derive a schema carrying extra
  columns a Record class cannot declare, because the set is only known at runtime (a registry with
  a column per registered dimension, a plugin's extension columns). Derivation, not construction
  from scratch: the Record stays the single source of truth for everything static, the result keeps
  the class's reflection data, and nothing downstream has to cope with a class-less `TableSchema`.
  Adding a name the class already declares throws rather than silently winning or losing. Lets
  schema tooling *see* columns that previously existed only as a hand-written `ALTER` run at boot.
- **`buildCreateTable(…, array $omitForeignKeys = [])`** — emit a `CREATE TABLE` with named FK
  constraints left out of the FK block, so a **circular** reference can be resolved: create one of
  the tables without the offending constraint, then add it with
  `ALTER TABLE … ADD {buildForeignKeyLine($fk)}` once both exist. No creation order satisfies a
  loop while every FK is inline, and this is the seam that lets evolution tooling break one
  deliberately rather than fail. A name matching no constraint is ignored, so callers can pass a
  set computed across the whole model instead of per table.
  **Breaking for external `SqlDialect` implementers** (interface signature change) — none known;
  the shipped dialects and the test double are updated.

## [0.11.0] - 2026-07-27

### Added

- **Schema-evolution seams for the `attrecord-migrations` companion** (design:
  [docs/arch-migrations.md](docs/arch-migrations.md) §8.1):
  - `SqlDialect::buildColumnLine(ColumnDefinition): string`,
    `SqlDialect::buildForeignKeyLine(ForeignKeyDefinition): string` and
    `SqlDialect::renderColumnType(ColumnDefinition): string` are now part of the dialect
    interface (previously private inside each dialect). They render the exact DDL fragments CREATE
    embeds — the full column line, the FK constraint line, and the bare type token (which
    PostgreSQL's `ALTER TABLE … ALTER COLUMN … TYPE <type>` needs alone) — so evolution tooling
    composes ALTER statements from the same rendering authority. On SQLite, `buildColumnLine()`
    always renders the non-PK form (the inline `INTEGER PRIMARY KEY AUTOINCREMENT` alias is a
    CREATE-TABLE-only concern).
    **Breaking for external `SqlDialect` implementers** (three new interface methods) — none known;
    the shipped dialects and test doubles are updated.
  - `#[Column(renamedFrom: 'old_name')]` — inert `?string` carried through to
    `ColumnDefinition::$renamedFrom`. Declares a column rename for the migrations differ (emitted as
    data-preserving `RENAME COLUMN` instead of destructive drop+add); never read by core CRUD or the
    DDL producer.

## [0.10.0] - 2026-07-25

### Added

- **Flag-set casters — a set of enum members in one column.** Two new `#[Cast]`-family attributes map
  a PHP `list<E>` (a set of flags) to/from a single column:
  - **`#[BitmaskCaster(EnumClass::class)]` (portable).** Stores the set as an **integer bitmask** on a
    plain `Int*` column — works on MySQL/MariaDB, PostgreSQL and SQLite. The enum must be int-backed
    with distinct positive **power-of-two** case values (one bit each), validated at schema-build.
    `toDb` OR-s the members, so the stored value is canonical (order- and duplicate-independent) and
    dirty tracking is stable for free; `fromDb` decomposes in declaration order and ignores stale bits.
    Empty set = `0`; a nullable column separates "unset" (`NULL`) from "empty set". Membership is a
    portable bitwise predicate (`WHERE (col & 4) = 4`).
  - **`#[SetCaster(EnumClass::class)]` (MySQL-only).** The self-documenting counterpart: stores the set
    in a native `ColumnType::Set` (`SET('a','b',…)`) column — members visible by name, queryable with
    `FIND_IN_SET`. MySQL-family by construction (the `Set` column type already throws on
    PostgreSQL/SQLite). The enum must be string-backed; on a `Set` column **omit `enumValues:`** — the
    `SET(...)` member list is derived from the enum's cases (mirroring `EnumCaster` on an `Enum`
    column). Canonical declaration-order join/split; empty set = `''`.

  Both share one PHP-side shape (a set of enum members); pick the storage: portable integer bitmask, or
  self-documenting MySQL `SET`. Docs: [README](README.md#column-casting),
  [docs/column-casting.md](docs/column-casting.md).

## [0.9.2] - 2026-07-24

### Fixed

- **PostgreSQL: `stored()` is now table-qualified.** A bare column in an `upsertByUniqueKey()` SET
  expression (`Record::stored()` / `UpsertColumn->stored`) was ambiguous inside PostgreSQL's
  `ON CONFLICT DO UPDATE SET` (target table vs `EXCLUDED`, SQLSTATE 42702). It now renders
  `table.col`, valid on all three engines. (New in 0.9.0, PG-only, never caught locally because PG
  was down.)
- **PostgreSQL: `upsertAll(strategy: Lockless)` rejects PK-null records** with a clear
  `AttrecordException`. Lockless coalesces on the PK, and an explicit `NULL` auto-increment PK is
  accepted by MySQL but rejected by PostgreSQL/SQLite (the sequence default fires only when the column
  is omitted, and one uniform-column statement can't omit it per-row). Carry the PK, or use the
  default `Locked` strategy / `insertAll()` for new rows. (New in 0.9.0.)
- **Read-back left records falsely dirty on PostgreSQL (regression since 0.7.0).**
  `hydrateFromRow()` / `patchColumnsFromRow()` snapshotted plain columns as the raw DB string, while
  `dirtyFields()` / `refreshSnapshot()` compare the canonical `toSnapshotString()`. For a `DateTime`,
  PG's raw `timestamp` string diverges from the canonical form, so an auto read-back left the record
  `isDirty()` — and that falsely-dirty timestamp then tripped a `text`-vs-`timestamp` datatype error
  in the keyed 3-step upsert's derived table. All snapshot writers now use `toSnapshotString()`, so a
  loaded/read-back row is byte-identical to the dirty-check. This fixes the entire block of PostgreSQL
  CI failures red since v0.7.0.

## [0.9.1] - 2026-07-24

### Changed

- **Renamed `UpsertStrategy::Native` → `UpsertStrategy::Lockless`.** "Native" begged the question
  "native to what?"; `Lockless` names the property the caller actually reasons about — it takes **no**
  `SELECT … FOR UPDATE` row locks, and so hands the caller the concurrency the default `Locked`
  strategy otherwise handles. Behaviour is unchanged. **Breaking** for the (one-day-old) `Native`
  case: replace `UpsertStrategy::Native` with `UpsertStrategy::Lockless` in `upsertAll(strategy:)`.

## [0.9.0] - 2026-07-24 — Single-table write gaps & scoped connection binding

### Added

- **Expression / `RawSql` SET in `upsertByUniqueKey()`.** `$updateColumns` now accepts, alongside the
  plain `list<string>` (each column set to the incoming value), a `column => RawSql` map for a
  per-column SET **expression** — the two forms may be mixed. Inside an expression, reference the
  incoming and stored row values portably with the new static helpers **`Record::incoming('col')`**
  (renders `VALUES(\`col\`)` on MySQL/MariaDB, `EXCLUDED."col"` on PostgreSQL/SQLite) and
  **`Record::stored('col')`** (the quoted column); bind literal values via the `RawSql`'s `?` params,
  which splice in after the INSERT `VALUES` params in map-iteration order. This makes conditional
  upserts — e.g. `name = CASE WHEN <incoming> <> '' THEN <incoming> ELSE <stored> END`, or a
  `CURRENT_TIMESTAMP` refresh — expressible in one native statement, closing the previous
  "`SET` is limited to `col = VALUES(col)`" gap. A string-keyed value must be a `RawSql` (a bare
  string is rejected); unknown columns throw `SchemaException`; expression SET is unsupported with
  `preserveAutoIncrement: true` (its plain-UPDATE path has no incoming row). New dialect method
  `SqlDialect::incomingRef()`; the legacy `list<string>` form is unchanged. `Record::upsertCol($col)`
  returns an `UpsertColumn` handle (`->name` / `->incoming` / `->stored`) whose `->setRaw($sql, $params)`
  yields a spreadable `[name => RawSql]` fragment — so a conditional expression can be written by
  interpolation and splatted in with `...$col->setRaw(…)`, naming the column once.
- **Insert-or-ignore (`OnConflict::Ignore`).** New `OnConflict` enum threaded through
  `RecordSet::insertAll(…, OnConflict $onConflict = OnConflict::Fail)` and the single-row
  `Record::save(…, OnConflict $onConflict = OnConflict::Fail)`. Under `Ignore` a row that would
  collide on a primary or unique key is **skipped** while the rest insert, instead of raising a
  `RecordSaveException`. Only key conflicts are absorbed — a NOT-NULL / CHECK / truncation error
  still surfaces — because attrecord emits `ON DUPLICATE KEY UPDATE <col> = <col>` (MySQL/MariaDB)
  or `ON CONFLICT DO NOTHING` (PostgreSQL/SQLite), **never** the blunt `INSERT IGNORE` /
  `INSERT OR IGNORE`. A skipped row gets no DB-generated id, so on an auto-increment table the PK is
  not back-filled under `Ignore` (a mixed insert/skip batch can't be aligned); `SaveResult::$inserted`
  counts only the rows really inserted, and a skipped `save()` leaves the record unsaved
  (`->_saved === false`), still new, with no PK. Intended for idempotent seeds and fire-and-forget
  batches. New dialect method `SqlDialect::insertIgnoreClause()`; `buildBulkInsert()` gains an
  `bool $ignore` flag; `buildInsertAllSql()` gains an `OnConflict` argument. The default
  (`OnConflict::Fail`) preserves prior behaviour exactly.
- **Native single-statement bulk upsert (`UpsertStrategy::Native`).** New `UpsertStrategy` enum
  (`Locked` | `Native`) on `RecordSet::upsertAll(…, UpsertStrategy $strategy = UpsertStrategy::Locked)`.
  `Native` emits **one** `INSERT … VALUES (…),(…) ON DUPLICATE KEY UPDATE …` (MySQL/MariaDB) /
  `… ON CONFLICT (pk) DO UPDATE SET …` (PostgreSQL/SQLite) — no `SELECT … FOR UPDATE`. It is the
  single-statement counterpart of the deadlock-safe 3-step `Locked` default, for a PK-keyed
  coalescing queue/outbox — especially one written *inside* an already-locked projection
  transaction, where the extra locks are undesirable. **Opt-in by design** (the tradeoff is a
  library-owner judgment call): under `Native` the caller owns the concurrency `Locked` handles,
  the conflict target is the **PK**, the SET is **uniform** (every row writes its incoming value to
  each dirty column — no per-row masking, so for homogeneous batches), ids are **not** back-filled,
  and `SaveResult::$inserted` carries the raw driver affected-row count (`$updated` = 0, no split).
  An empty update set degrades to insert-or-ignore. New dialect method
  `SqlDialect::buildBulkUpsertSql()`, which composes with the expression/`RawSql` SET convention.
  The default (`Locked`) preserves prior behaviour exactly.
- **Scoped per-operation connection/session binding.** New static `Record::usingConnection(Connection
  $connection, callable $fn)` and `Record::usingSession(DbSession $session, callable $fn)` run every
  Record/RecordSet operation inside the closure against an explicitly supplied connection/session,
  then restore the previous binding (even on throw; nesting restores to the enclosing scope, not the
  global default). The scoped binding wins over both a per-class and the default connection. This lets
  a caller run a unit of work against a **specific** session rather than the ambient global one — e.g.
  a projection participant handed an engine-scoped session that must carry the write, or a store
  keeping its attrecord ops on the exact injected session its raw-SQL siblings use (which also makes
  the write observable in a unit test with no global-state juggling). `usingSession()` binds only the
  session and carries the current dialect over (same-engine alternate session — the common case).

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

[Unreleased]: https://github.com/Nandan108/attrecord/compare/v0.15.0...HEAD
[0.15.0]: https://github.com/Nandan108/attrecord/compare/v0.14.1...v0.15.0
[0.14.1]: https://github.com/Nandan108/attrecord/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/Nandan108/attrecord/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/Nandan108/attrecord/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/Nandan108/attrecord/compare/v0.11.0...v0.12.0
[0.1.1]: https://github.com/Nandan108/attrecord/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Nandan108/attrecord/releases/tag/v0.1.0
