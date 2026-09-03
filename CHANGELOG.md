# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`Record::deleteUnreferenced(array $keys, ?string $column = null): int`** — deletes those of
  `$keys` that nothing points at, returning how many were actually removed. The reaper's primitive,
  for a table whose rows may be retired once no document references them.

  **One statement**, a correlated `NOT EXISTS` per referring column, so the question is part of the
  delete rather than a query with a window after it. Referrers are read from the live catalogue, so a
  table that starts pointing at yours later is covered without anyone remembering a list. Only the
  key list is bound — the guards carry no parameters — so a call costs one placeholder per key
  however many tables reference the target, where the two-step alternative binds referrers x keys.

  **The inner tables are aliased, and that is correctness rather than style.** On a self-referencing
  table an unaliased `NOT EXISTS (SELECT 1 FROM t WHERE t.parent_id = t.id)` has its inner `t` shadow
  the outer one, so the correlation asks whether a row is its *own* parent — true of nobody. The
  predicate then matches every candidate and the check silently checks nothing. That is survivable
  only where every referring key is `ON DELETE RESTRICT`, since the engine refuses what the predicate
  let through; against a `CASCADE` the same wrong predicate would delete the referring rows too.
  There is a regression test on a self-referencing fixture for exactly this.

  Goes through the delete guard, so an `AppendOnly` Record refuses and an `Immutable` one passes.
  A composite primary key throws, as elsewhere; so does a `$column` the Record does not declare.

- **`ColumnRole` + `TableSchema::columnRole()` / `columnsByRole()`** — who writes a column, and when,
  answered from metadata the schema already holds: `PrimaryKey`, `Generated`, `Managed`
  (`#[CreatedAt]` / `#[UpdatedAt]` / `#[Version]`), `Exempted` (`#[Mutable]`), `Content` (yours,
  stated at insert). Evaluated in that order, since a column can answer to more than one test — an
  auto-increment primary key is engine-written too, but what it *is* is the key.

  It exists for the **content-addressed** table, whose primary key is a digest of its own facts:
  `columnsByRole(ColumnRole::Content)` is exactly the set to hash, so the digest stops being a
  hand-written list of column names a later column can quietly fall out of. Assembling the same
  answer at a call site means consulting four unrelated properties and remembering all of them, and
  the one such a list usually keeps by mistake is `#[Version]` — it reads like a fact about the row
  until you ask who increments it, which is the shape of thing that survives review unchallenged.

  **Computed on call, not stored.** It is a handful of comparisons over metadata already held, so
  precomputing it into every `TableSchema` would cost every schema in the process to serve the few
  that ask; a caller that finds it hot can memoise where it knows its own access pattern.

  On an ordinary Record `Exempted` is empty and the rest is `Content` — the role says who supplies
  the value, while whether it is then frozen is a property of the class rather than of the column.

- **`#[Mutable]`** (property-level) — exempts one column from an {@see Immutable} Record's promise,
  so a row whose *content* is fixed can still carry metadata laid over it.

  The motivating case is the content-addressed row, where the boundary is already written down: the
  primary key is a digest of the identity-bearing columns, so those cannot change without breaking
  the row's own identity — but a column outside the digest was never part of it. A validity flag
  ("these contact details are dead") is a fact *about* the interned facts, true for every document
  that ever stated them. The other canonical case is an append-only outbox with a `dispatched_at`:
  the row records that an event happened and that never changes; whether it has been sent is
  bookkeeping.

  An update is permitted exactly when **every** column it would write is exempted, decided from the
  columns actually being written rather than the ones the caller asked for — dirty tracking for
  `save()`, the given set for `updateWhere()`, the resolved set for `updateByWhere()` (where an empty
  `$fields` means "every non-null column"). The exception names the offending column, which with some
  columns moving is the only useful thing it can say.

  **Declared at the field**, not as a class-level list: a list sits far from the columns it exempts
  and rots as they change, whereas at the property a reader of that column sees it moves and a reader
  of any other sees no marker and can still trust the promise. That placement is what keeps the
  row-level marker worth having — the guarantee is narrowed in one visible place rather than hollowed
  out from a distance.

  It relaxes nothing else. `upsertAll()` and `upsertAllByUniqueKey()` stay refused however many
  columns are exempted, since whether either inserts or updates is decided per record at runtime, so
  neither can be relied on to touch only the exempted ones; deletes remain `AppendOnly`'s question.
  And it throws at schema-build time on a Record that is neither marker, on a primary key, or on a
  generated column — in each case it would exempt the column from nothing while telling a reader it
  moves, which is worse than not writing it.

### Documentation

- **The README documents the write-once markers and the exception list.** `AppendOnly` had never
  appeared there — it was described only as a *use case* for `insertAll()` — and an audit of the LLM
  reference against the human docs also turned up `RecordDeleteException`, `LockAssertionException`
  and `TransactionException` with no human documentation at all.

## [0.20.0] - 2026-09-02

**`Immutable`: content that never changes, existence that may.** `AppendOnly` conflated two promises
— that a row's content is fixed, and that the row itself is permanent — and a consumer turned up
that needs only the first.

### Added

- **`Nandan108\Attrecord\Immutable`**, a marker for Records whose rows never change once written.
  Every *update* path throws (`save()` on an existing row, `updateWhere()`, `updateByWhere()`,
  `upsertAll()`, `upsertAllByUniqueKey()`); **`delete()`, `deleteWhere()` and `deleteAll()` are
  permitted**, which is the whole of the difference.

  The case it was built for is the **content-addressed** row — one whose key is a digest of its own
  fields, interned and shared by everything stating the same facts. Editing it is incoherent: it
  breaks the key that identifies it, silently, for every other holder. But reaping one that nothing
  references loses nothing, because re-interning the same facts recomputes the *same* key — so an
  orphan is a rebuildable cache entry, not a record of anything. `AppendOnly` forbade that reaping,
  which left the operation expressible only by dropping out of the Record layer into raw SQL.

  Choose between the two by asking **what the row claims**, not how much protection you want: a
  ledger row asserts that an event occurred, so deleting it rewrites history exactly as editing it
  would; a content-addressed row asserts only that these facts go by this key, which stays true
  whether or not the row is stored anywhere.

### Changed

- **`AppendOnly` now extends `Immutable`** and is otherwise unchanged — same guards, same
  exception, same behaviour for every existing implementer. Extending rather than duplicating is
  what keeps the split honest: the update guards test `Immutable` alone, so an append-only Record
  reaches them without restating anything, and the two markers cannot drift apart.

- **`AppendOnlyViolationException` names the marker the class actually carries.** It previously said
  "is append-only (implements AppendOnly) … never update or delete" for every violation, which would
  now be wrong twice over on an `Immutable` Record: wrong about the interface, and actively
  misleading about `delete()`, which is available and is very likely what the reader wants. The
  marker is read off the class rather than passed in by each guard, so a guard cannot produce a
  confidently wrong message by forgetting an argument.

### Documentation

- **`UpsertStrategy::Locked` now names the one deadlock shape it does not cover** (landed after
  v0.19.0 was tagged, so it ships here). `Locked` is deadlock-safe against lock-order *inversion*,
  which is what its ordered `FOR UPDATE` was built for; it says nothing about a **lock-conversion**
  deadlock, where two writers upsert the same *existing* key and each holds the shared lock that
  InnoDB's `INSERT IGNORE` leaves behind while asking to upgrade it to exclusive. Ordering cannot
  help — an inversion needs two or more resources to have an order at all — so with a single key the
  protection has no grip.

### Notes

Additive: no existing implementer changes, `instanceof AppendOnly` keeps its meaning, and a Record
that wants the weaker promise opts in by naming `Immutable` instead.

## [0.19.0] - 2026-08-23

**Three markers for what a table's shape does *not* say.** A `TableSchema` describes what exists,
which leaves the companion converger unable to answer three questions that no pair of schemas
contains: is this live object the same one under a new name, is it retired, or is it not ours at
all. All three additions are **inert in core** — collected onto `TableSchema`, never read by CRUD
and never emitted into DDL, in the same sense as `#[Column(renamedFrom:)]` before them.

### Added

- **`renamedFrom:` / `renamedSince:` on `#[Index]` and `#[UniqueKey]`**, collected as
  `TableSchema::$indexRenames`. Until now only a *column* could declare its former name. An index
  rename is the more urgent case in practice: it looks to a differ like an unrelated index appearing
  and another disappearing, and those two halves classify differently, so a converger applying only
  its safe half builds the new index and keeps the old one indefinitely — no error, double the write
  cost. Shape-matching recovers the ordinary case on its own; a declaration is what survives an index
  being renamed *and* reshaped in one release, where nothing relates the two.

  On a composite declared property-by-property the rename may be repeated on each member; agreeing
  repeats fold, disagreeing ones throw. `renamedSince` without `renamedFrom` throws — a version dates
  a rename, it does not declare one.

- **`#[Absent(index:, uniqueKey:, foreignKey:, check:, column:, since:)]`**, collected as
  `TableSchema::$absent` — a named object that must **not** exist. Each parameter takes one name or a
  list; the attribute is repeatable, so objects retired in different releases carry their own version.

  It states a fact about the present rather than recording an event, which is what makes it
  idempotent: on an install that never had the object there is nothing to do, so a schema carrying
  `#[Absent]` still converges to an empty plan. A "drop this" step cannot have that property without
  a ledger key to remember itself by.

  `since:` is **opaque** — stored, never compared. This library does not know whether a consumer
  ships semver, dates or plugin versions, and PHP's own `version_compare('1.04.0', '1.4.0')` answers
  *equal*, so comparing here would be a quiet wrong answer rather than a loud unsupported one. It is
  for the author, and for a pruning tool that knows the scheme.

- **`#[Unmanaged(index:, uniqueKey:, foreignKey:, check:, column:)]`**, collected as
  `TableSchema::$unmanaged` — a named object that exists on purpose, by another authority, and is
  never to be converged or dropped. The DBA's covering index is the case: it forbids nothing, so
  nothing distinguishes it from a leftover of our own except knowledge that no introspection can
  recover, and left undeclared it reads as drift on every check until the reader learns to skim the
  check. The companion's `PartiallyDeclared` answers the same question for a whole table and pays by
  going quiet about all of it; naming one object leaves the rest under the differ's eye.

- **`SchemaObjectKind`** — `index` / `unique_key` / `foreign_key` / `check` / `column` as a value,
  with `label()` for messages. Both markers group by it, so a name legitimately reused across kinds
  stays unambiguous.

- **`RenameDefinition`** and **`AbsentDefinition`** value objects, plus `renamedSince` on
  `ColumnDefinition` beside the existing `renamedFrom`.

### Notes

Declaring an object both present and absent, absent twice, or absent *and* unmanaged throws a
`SchemaException` at schema-build time — where it is written, rather than as a puzzling plan later.

Nothing here is required to keep working: every parameter is optional and trailing, every new
`TableSchema` property is additive.

## [0.18.0] - 2026-08-21

**`ReferenceReader` — "what points *at* this row?"** A Record declares the foreign keys it owns, so
the DDL producer could always say what a table points at. The inbound direction is in no Record at
all — it is spread across every other table in the schema — so it is read from the live catalogue.

### Added

- **`ReferenceReader::inboundForeignKeys($session, $table, ?$column)`** → `list<InboundReference>`:
  the child table, child column, constraint name, referenced column, and `ON DELETE` action of every
  foreign key pointing at a table (optionally at one of its columns). `InboundReference` is a
  read-model of its own rather than a `ForeignKeyDefinition`, which describes a constraint a Record
  *declares* and resolves its target lazily — this is the other direction, from a different source,
  and about child tables that may have no Record at all.

- **`ReferenceReader::referencedKeys($session, $table, $column, $keys)`** → the subset of `$keys`
  that some row somewhere references. **Bulk only, by design**: the set is answered in a `UNION` over
  the referring tables, and there is deliberately no single-key primitive, because a query per key is
  how a "which of these 500 can I remove" screen becomes a minute of database time. `array_diff()`
  gives the unreferenced complement. Empty `$keys`, or a table nothing references, returns `[]`
  without touching the database.

  It returns **the caller's own values, in the order given** — not the driver's rendering of them.
  That distinction is not cosmetic: mysqli hands back a signed `BIGINT` column as a numeric *string*
  whatever was bound, so returning what the database said would quietly break
  `in_array($key, $result, true)`, `array_flip()` and `array_intersect_key()` — each a natural thing
  to do with a key set, and each would then report a referenced row as free to delete.

  Large sets are **split across statements internally**. A UNION binds the key set once per referring
  column, so a call costs referrers × keys placeholders, and every supported engine caps that:
  MySQL and PostgreSQL speak a 16-bit parameter count, SQLite's `SQLITE_MAX_VARIABLE_NUMBER` defaults
  lower still. Past the cap a statement does not run slowly, it fails — so a caller asking about
  40 000 keys is using the method as intended and should not have to know.

- **`ReferenceReaders::for($dialect)` / `::forConnection($connection)`** — the resolver. Kept off
  `SqlDialect` deliberately: every method there builds a string and executes nothing, which is what
  makes a dialect testable without a database, and on SQLite this is not one statement's worth of
  string at all. An unrecognised dialect throws rather than guessing at a catalogue.

### What it is, and what it is not

**Reporting, not delete safety.** A key declared `ON DELETE RESTRICT` already makes the delete safe:
the engine refuses it, atomically, with no race. Asking first and deleting after is a check-then-act
with a gap in the middle. Ask in order to *tell someone* what is holding a row, or to count what is
unreferenced, before anything is attempted.

**Catalogue-only, permanently.** A referrer that stores a key without a foreign key — an id inside a
JSON document, a meta row, a table in another schema — is invisible here. That is a property of the
question rather than a gap in the answer.

**Memoized per instance**, keyed by table and column: an `information_schema` scan is slow on a
server with thousands of tables, which is ordinary shared hosting, and the answer changes only when
the schema does. Per instance rather than statically, so lifetime stays the caller's decision — hold
a reader for a request.

### Three engines, three different catalogues

- **MySQL / MariaDB** — `KEY_COLUMN_USAGE` joined to `REFERENTIAL_CONSTRAINTS` for the delete rule,
  filtered on `REFERENCED_TABLE_NAME`: the same rows the outbound question reads, from the other end.
  Scoped to `DATABASE()` on both sides, or a same-named table in another schema on the same server
  contributes rows that have nothing to do with this install.
- **PostgreSQL** — `pg_constraint`, not the standard views: `confrelid` *is* the referenced table, so
  inbound is a plain equality instead of a four-view join, and `constraint_column_usage` is
  permission-filtered in a way that can hide constraints from the very caller asking. `conkey` and
  `confkey` are unnested **together**, so a composite key's column pairs stay matched rather than
  becoming their cross product. `to_regclass()` resolves through `search_path`, which scopes the
  answer for free.
- **SQLite** — no constraint catalogue at all, and `PRAGMA foreign_key_list(t)` reports one table's
  *outbound* keys. The obvious consequence would be a pragma per table in a loop; it is avoidable,
  because every pragma is also a table-valued function, so `pragma_foreign_key_list(m.name)` joins
  against `sqlite_master` and answers the whole schema in one statement. SQLite also stores no
  queryable constraint *name* — the pragma reports an ordinal — so `$constraintName` there is
  `childTable.childColumn`, which is the identity that actually exists, rather than a fabricated
  match for whatever the DDL happened to call it. A constraint written `REFERENCES parent` with no
  target column reports `to` as null; the parent's primary key is resolved rather than passed on, so
  a caller filtering by column need not know that spelling exists.

## [0.17.1] - 2026-08-18

### Fixed

- **A `#[Check]` constraint name now includes the table, so the same rule on two tables does not
  collide.** MySQL scopes CHECK constraint names per *database*, and 0.17.0's name carried the table
  prefix and the expression but not the table — so two Records declaring the same rule, say
  `#[Check('qty_non_negative', 'qty >= 0')]` on an order line and on a purchase-order line, derived
  one name and the second `CREATE TABLE` failed with `ERROR 3822 Duplicate check constraint name`.
  That is the very collision the prefix digest exists to prevent, one scope in.

  The prefix digest becomes a **scope** digest over prefix *and* table, so it distinguishes both
  axes at the same six characters. Digested rather than spelled out, so the name stays within the
  64-character limit for any table name; the declared rule name, which is the half that says what
  was broken, stays legible in the middle either way.

  **This changes emitted constraint names**, for a feature published the same day. A database
  created with 0.17.0 carries the old names; `attrecord-migrations` will read them as undeclared and
  propose replacing them, which is correct and the intended repair.

## [0.17.0] - 2026-08-18

**`#[Check]` — table-level CHECK constraints.** A boolean expression every row must satisfy,
declared on the class, emitted into `CREATE TABLE` on all three dialects and available as a
fragment for `ALTER TABLE … ADD`.

The rules that motivate it are the ones a column cannot express: *only a unit may be tracked*,
*a batch must name its parent*. Both are conditional — a legality rule and a NOT NULL that applies
to one kind of row — and both are already enforced in the application that owns them. What the
constraint adds is the writes that never reach that application: a CLI, a neighbouring plugin,
someone at a SQL prompt.

**Contains a breaking change (`!`)** for external `SqlDialect` implementers — one new interface
method. None are known outside this repository.

### Added

- **`#[Check(name, expression)]`, class-level and repeatable.** The expression is passed to the
  engine **verbatim**: nothing here parses it, which is what makes each engine's full expression
  language available and equally leaves portability to the author — a MySQL-only function fails at
  `CREATE TABLE` on PostgreSQL, loudly, which is the right moment to find out.

  A CHECK is row-local by nature. It sees one row's columns, cannot query another table or row (no
  engine allows a subquery here), and is not evaluated against existing rows until something
  rewrites them. Rules spanning rows stay in the application, with the constraint carrying the
  single-row *projection* of the rule.

  Refused at schema-build time: a repeated name, an empty name or expression, and a name colliding
  with the `chk_<column>_enum` constraint that carries an enum column's members on PostgreSQL and
  SQLite — taking that name would replace the member list with a rule and drop the enum's
  enforcement on those two backends.

  **Enforcement is version-dependent in the direction that hurts**: MySQL enforces from 8.0.16 and
  **parses-then-ignores** the clause on 8.0.0–8.0.15, so the DDL succeeds and the guarantee is
  absent. MariaDB enforces from 10.2.1; PostgreSQL and SQLite always. A consumer that cannot pin its
  host's version should treat a CHECK as defence in depth, never as the only guard.

- **`SqlDialect::buildCheckLine(CheckDefinition): string`** — the same fragment `CREATE TABLE`
  embeds, exposed for `ALTER TABLE … ADD`, alongside `buildColumnLine()` and
  `buildForeignKeyLine()`. One rendering authority for both statements, forever.

  **Breaking for external `SqlDialect` implementers** (one new interface method) — none known;
  the three shipped dialects and the test double are updated.

- **`TableSchema::$checks`** — `array<string, CheckDefinition>` keyed by *emitted* constraint name,
  each definition also carrying the declared name and the expression as written.

### Constraint naming — two digests, two problems

The emitted name is `chk_{prefixDigest}_{declaredName}_{expressionDigest}`.

The **prefix digest** is 0.16.0's foreign-key story repeated: MySQL scopes CHECK constraint names
**per database** — verified, `ERROR 3822 Duplicate check constraint name` — so two installs sharing
one database would collide on an identical declaration. Same mechanism, same six hex characters,
omitted entirely when there is no prefix. MariaDB, PostgreSQL and SQLite scope per table and would
not have needed it.

The **expression digest** has no foreign-key equivalent, and it is the more interesting half. No
engine gives the expression back the way it was written: MySQL re-prints it with charset introducers
and brackets of its own (`((\`kind\` = _latin1'unit') or …)`), PostgreSQL adds `::text` casts. So
schema tooling comparing a live expression to a declared one cannot distinguish *the author changed
the rule* from *the engine spells it differently* — and the fail-safe reading of that ambiguity,
assume the engine, is exactly the one that silently withholds a corrected rule from every database
that already has the old one. That failure mode is not hypothetical; it is what generated-column
expressions did until `attrecord-migrations` 0.5.2.

Digesting the expression into the name removes the comparison from the problem. An edited expression
**is** a differently-named constraint, so name-only convergence adds the new one and drops its
predecessor, with no expression comparison anywhere. Whitespace is normalized before digesting, so
re-indenting an expression is not a schema change. Long declared names fold to stay within the
64-character identifier limit, keeping both digests.

## [0.16.0] - 2026-08-08

**Several prefixed installs may now share one database, on MySQL and MariaDB.** Two WordPress sites
at `wp_` and `blog_`, or a platform cutover running two hosts against one database during the
transition — configurations the table-prefix feature has always implied and which silently did not
work, because foreign-key constraint names dropped the prefix and InnoDB scopes those names per
*database*, not per table. The second install's `CREATE TABLE` failed with errno 121. That is the
headline here; the rest of the entry is how the name stays unique without overflowing the
64-character identifier limit.

**Not on PostgreSQL**, and no constraint-naming change can fix that: index and unique-key names are
user-declared and live in the schema-wide relation namespace, so a second install collides on
`relation "uniq_sku" already exists` before any constraint name is reached. Use a schema per install
there — it isolates everything rather than just names. Verified scoping table in
[ddl-generation.md](docs/ddl-generation.md#fk-constraint-naming--and-why-several-installs-may-share-one-database).

Alongside it, a feature about the same underlying theme — values a database round-trips differently
than you wrote them — this time JSON object keys, which only half the supported engines preserve.

**Contains a breaking change (`!`)** — existing databases carry the old foreign-key constraint
names. Leaving them is safe; the reasoning is under the entry.

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

  **Both naming paths are covered** — `#[Relation]`-derived keys *and* class-level `#[ForeignKey]`
  (constraint-only) keys. They are named at two separate sites, and the first pass of this fix
  converted only one, leaving every constraint-only FK still colliding. Nothing caught it because no
  test exercised that path with a prefix set; one does now.

  **Long names are folded rather than truncated blindly.** `fk_ + digest + table + column` still
  overflows on real schemas — InvFlux's
  `invflux_subject_stock_management_events.reconciliation_run_id` reaches 71 — so past the limit the
  *column* becomes a 10-hex digest and the table name is kept, that being the more useful half in an
  error message. The result is deterministic and provably ≤ 64 for any input.

  **Why this is marked breaking, and what converging an existing database does.** Existing databases
  carry the old constraint names, so a converged install sees FK-name drift. Fresh installs are
  unaffected — declared and live names agree, and the golden invariant holds.

  **Pair this with `attrecord-migrations` ^0.5.** That release recognises a renamed constraint by
  *shape* and emits it as a single atomic `rename_foreign_key` change, so the drift converges
  cleanly: nothing is touched at the default `Safe` ceiling on MySQL/MariaDB, one `Destructive` run
  completes it and re-plans empty, and PostgreSQL applies it at the default ceiling via
  `RENAME CONSTRAINT`. Verified against MariaDB and PostgreSQL.

  On **`attrecord-migrations` ^0.4 or earlier** the rename is planned as two independent changes —
  `add_foreign_key` (`Safe`) and `drop_foreign_key` (`Destructive`), MySQL having no
  `RENAME CONSTRAINT` — so a default-ceiling run applies the add and skips the drop, leaving the
  column carrying **both** constraints plus a redundant backing index, and a plan that never
  re-plans empty. Upgrade the companion, or run one `Destructive` convergence, or rebuild.

  There is no pressure to converge at all: the collision this fixes can only happen at
  `CREATE TABLE`, so a database that already holds an install has by definition not collided.

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

[Unreleased]: https://github.com/Nandan108/attrecord/compare/v0.20.0...HEAD
[0.20.0]: https://github.com/Nandan108/attrecord/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/Nandan108/attrecord/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/Nandan108/attrecord/compare/v0.17.1...v0.18.0
[0.17.1]: https://github.com/Nandan108/attrecord/compare/v0.17.0...v0.17.1
[0.17.0]: https://github.com/Nandan108/attrecord/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/Nandan108/attrecord/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/Nandan108/attrecord/compare/v0.14.1...v0.15.0
[0.14.1]: https://github.com/Nandan108/attrecord/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/Nandan108/attrecord/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/Nandan108/attrecord/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/Nandan108/attrecord/compare/v0.11.0...v0.12.0
[0.1.1]: https://github.com/Nandan108/attrecord/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Nandan108/attrecord/releases/tag/v0.1.0
