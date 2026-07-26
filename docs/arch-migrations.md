# Schema evolution (design note — the `attrecord-migrations` companion package)

> **Status:** PLANNED — to be built as **`attrecord-migrations`**, a companion package, with two
> small seams added to core (§8.1). Nothing implemented yet; this note is the design contract for
> that build. It is deliberately *capped*: §7 (Non-goals) is the fence, and §8 explains why the
> companion package — not core — is the vehicle.
> **Theme:** the Record class is already the schema. Evolution is therefore **convergence** —
> introspect the live database, diff it against the attribute-derived `TableSchema`, and apply a
> classified, guarded `ALTER` plan — not a second source of truth maintained as a chain of
> migration files.

## 1. Thesis: declarative convergence, not migration files

The backlog sketch for this feature ("a `migrations` ledger table + an ordered, idempotent
apply/rollback runner") described the Laravel/Doctrine-migrations shape: hand-written, versioned
migration scripts as the unit of change. This note **deliberately deviates** from that sketch, for
three reasons that only became clear against a real consumer:

1. **A migration chain is a second schema source of truth.** attrecord's core pitch is that the
   attributes *are* the schema — `buildCreateTable()` derives fresh-install DDL from them. A file
   chain would carry the same information again, and the two *will* drift (the chain says the
   column exists; the attribute says otherwise; which is right?). Convergence keeps one authority:
   the Records describe the **desired state**, always.
2. **The flagship consumer cannot run deploy-time scripts.** InvFlux is a WordPress plugin: it is
   updated by file replacement, the database may be at *any* prior state (or partially installed,
   or brand new), several sites share one codebase, and there is no operator running a `migrate`
   command. What a plugin can do is *converge on upgrade*: look at what is live, apply the delta.
   InvFlux already hand-rolls exactly this — `information_schema` probe → conditional `ALTER` —
   in half a dozen `ensure*()` methods (§9). This package is those methods, generalized.
3. **Rollback falls out for free.** In a chain model, "down" migrations are the acknowledged hard
   part (the backlog said so). In a convergence model there *are* no down scripts: rolling back
   means converging toward an older desired state (the previous release's Records) — the same
   diff/plan/apply machinery, pointed at older code. Data destroyed by a destructive change is
   unrecoverable in either model; convergence just stops pretending otherwise.

The trade: convergence cannot express **data** transformations (backfills, splits, cross-table
moves) or resolve **rename ambiguity** on its own. §4.3 (declared renames) and §6 (hooks) address
those honestly rather than smuggling a script-chain back in.

## 2. Why it is cheap here

Like the unit of work, this is mostly a **coordinator over machinery core already owns**:

| Piece the converger needs | attrecord already has |
| --- | --- |
| The desired state | `TableSchema::fromClass()` — columns, PK, unique keys, indexes, FKs, all `public readonly` |
| Rendering a column/index/FK as SQL | the DDL producer's fragment builders (`buildColumnLine()`, `buildForeignKeyLine()`, …) — private today, promoted by seam §8.1-1 |
| Dialect quoting/literals | `SqlDialect::quoteIdentifier()` / `toLiteral()` |
| Concurrency control for the apply | `DbSession::withAdvisoryLock()` — already on the session interface |
| A worked introspection precedent | the consumer's `information_schema` probes (§9) |

The genuinely *new* parts are: per-dialect **introspection** (live schema → the same model shape),
**normalization** (the correctness core, §4.2), the **diff + classifier** (§4.3), and the
**plan/apply runner** (§5). No new dependencies; the package depends only on attrecord.

## 3. The shape: `plan()` / `apply()`

Two verbs, Terraform-style, both explicit:

```php
$migrator = new SchemaMigrator($connection);           // attrecord-migrations
$plan = $migrator->plan([OrderRecord::class, OrderLineRecord::class, …]);

$plan->isEmpty();          // fast path: nothing to do
$plan->statements();       // list<PlannedChange> — SQL + classification + reason, inspectable
$plan->hasDestructive();   // anything beyond the additive set?

$migrator->apply($plan);                               // Safe changes only (default)
$migrator->apply($plan, allow: ChangeClass::Destructive); // opt-in escalation
```

- **`plan()` is pure** — reads `information_schema`/`PRAGMA`, writes nothing, always safe to call.
  It is the inspection surface: a consumer can log it, display it in an admin diagnostic, or diff
  it in CI ("does the code's schema match staging?") without ever applying.
- **`apply()` is explicit and guarded** — never runs from a destructor, a query, or a bootstrap
  side effect (mirror of the UoW's "no auto-flush"). It executes the plan's statements in
  dependency order (§5) under an advisory lock, and records the run in the ledger (§5.3).

### 3.1 Change classes

Every planned change carries one of three classes; `apply()`'s `allow:` parameter is a ceiling.

| Class | Meaning | Examples |
| --- | --- | --- |
| **Safe** (default ceiling) | Additive or metadata-only; cannot lose data or reject existing rows | `ADD COLUMN` (nullable, or defaulted), `ADD INDEX`, `ADD UNIQUE KEY`*, `ADD CONSTRAINT … FOREIGN KEY`*, widening type changes (`SMALLINT → INT`, `VARCHAR(64) → VARCHAR(191)`), adding a default, declared renames (§4.3) |
| **Destructive** (opt-in) | Can lose data or reject rows; still mechanically expressible | `DROP COLUMN`, `DROP INDEX`/`KEY`, `DROP FOREIGN KEY`, narrowing type changes, `NULL → NOT NULL`, shrinking `VARCHAR`, removing enum/`SET` members |
| **Manual** (never auto-applied) | The differ cannot prove what the right statement is | unparseable/unknown live column type, live default it cannot normalize (§4.2), PK change, `AUTO_INCREMENT` flag change, table-options drift (engine/charset/collation), anything SQLite needs a table rebuild for until §4.4 lands |

*Two Safe entries deserve honesty: `ADD UNIQUE KEY` fails on existing duplicate data, and
`ADD CONSTRAINT FOREIGN KEY` fails on existing orphan rows. They stay in Safe because they cannot
*lose* data — the failure mode is a loud, atomic statement error that aborts the run (§5.2), not
silent corruption. The plan flags them with a `mayRejectExistingRows` marker so a cautious consumer
can pre-check.

**Fail-safe bias.** Anything the differ is not *sure* about is Manual, and Manual is never
auto-applied — it surfaces in the plan with a human-readable reason. A convergence tool's worst
failure is confidently applying the wrong `ALTER`; the design prefers "tells you it doesn't know."

## 4. The pipeline

```
Record classes ──TableSchema::fromClass()──► desired model ─┐
                                                            ├─► normalize ─► diff ─► classify ─► Plan
live database ──introspect (per dialect)──► live model ─────┘
```

### 4.1 Introspection

Per dialect, into the **same model shape** the desired side uses (a lightweight `LiveTable` /
`LiveColumn` mirror of `TableSchema`/`ColumnDefinition` — not the real classes, which carry
PHP-side fields like `propertyName`/`caster` that have no live counterpart):

- **MySQL/MariaDB** — `information_schema.COLUMNS` (type, nullability, default, extra),
  `STATISTICS` (indexes/unique keys, column order), `KEY_COLUMN_USAGE` +
  `REFERENTIAL_CONSTRAINTS` (FKs + actions). The consumer precedent (§9) already reads `COLUMNS`.
- **PostgreSQL** — `information_schema` for columns/constraints, supplemented by `pg_catalog`
  (`pg_attrdef`, `pg_index`) where `information_schema` is lossy — notably detecting
  `SERIAL`/`BIGSERIAL` (which introspect as `integer` + a `nextval(…)` default) and expression
  defaults.
- **SQLite** — `PRAGMA table_info` / `table_xinfo`, `index_list` + `index_info`,
  `foreign_key_list`.

### 4.2 Normalization — the correctness core

The live and desired models never match textually: MySQL reports `int(11)`/`tinyint(1)`, PG
reports `character varying` and casts defaults (`'0'::smallint`), `SERIAL` round-trips as
`integer + nextval`, MariaDB may report `current_timestamp()` where the attribute says
`CURRENT_TIMESTAMP(6)`. Diffing raw strings would produce endless false positives — and false
positives in a tool that emits `ALTER TABLE` are not noise, they are danger.

So both sides are reduced to a **canonical comparison tuple** per column —
`(canonical type, length/precision/scale, unsigned, nullable, canonical default, auto-increment,
generated-expr, enum/set members)` — by per-dialect normalizers that own all the aliasing:

- type aliases: `INT(11)` ≡ `INT`; `TINYINT(1)` ≡ `Bool`; `character varying(n)` ≡ `VARCHAR(n)`;
  `BIGSERIAL` ≡ `BIGINT + autoIncrement`; SQLite affinity quirks.
- default-value canon: strip PG casts and quotes; unify `CURRENT_TIMESTAMP` spellings and
  precision; distinguish "no default" from `DEFAULT NULL` only where the engine does.
- collation/charset: **excluded** from the column tuple (host-managed; table-level drift is
  Manual, §3.1).

**The escape valve is classification, not guessing:** any live value a normalizer cannot
confidently reduce (an unknown type, an expression default it does not recognize) makes that
column's diff **Manual** with the raw live value quoted in the reason. This rule is what makes the
normalizers safe to grow incrementally — an unhandled case degrades to "look at this," never to a
wrong statement. It also bounds the test surface: every normalizer case is a pure
string-in/tuple-out unit test.

### 4.3 Diff and classification

Table-level: a desired table missing live → `CREATE TABLE` (the existing producer, verbatim —
Safe). A live table absent from the desired set → **ignored** (not dropped): the converger only
manages tables it is given; other tables on the host (WordPress's own, other plugins') are none of
its business. An explicit `dropUnmanaged:` opt-in is *not* offered — see §7.

Column-level, per table: match by name; then

- desired-only → `ADD COLUMN` (Safe if nullable/defaulted; Manual if `NOT NULL` with no default on
  a table that has rows — the engine would reject it or, on MySQL, silently zero-fill; a backfill
  hook (§6) is the honest path).
- live-only → `DROP COLUMN` (Destructive).
- both, tuples differ → `MODIFY`/`ALTER COLUMN`, classified by direction: widening = Safe,
  narrowing / nullability-tightening / member-removal = Destructive, unsure = Manual.
- **Renames are declared, never inferred.** A drop+add pair is otherwise indistinguishable from a
  rename, and guessing (by type-similarity heuristics) is exactly the confident-wrong failure §4.2
  refuses. Core gains an inert metadata field (seam §8.1-2):

  ```php
  #[Column(ColumnType::VarChar, length: 64, renamedFrom: 'sku_code')]
  public string $sku = '';
  ```

  The differ, seeing desired `sku` absent live *and* live `sku_code` absent desired, emits
  `RENAME COLUMN` (Safe — data-preserving) instead of a Destructive drop + add. `renamedFrom` is
  permanent, cheap documentation of the column's history; a live DB matching neither name is
  simply missing the column (`ADD`), and one matching the *current* name ignores the marker.

Index / unique-key / FK level: compared by **name + column list** (+ FK target/actions). Additions
are Safe (with the §3.1 marker); drops are Destructive; a same-name-different-definition entry
plans as drop **then** add — the pair inheriting Destructive, so a redefinition never silently
slips through under the Safe ceiling.

### 4.4 SQLite: the rebuild boundary (phased)

SQLite's `ALTER TABLE` covers only `RENAME TABLE`, `ADD COLUMN` (with restrictions — no
UNIQUE/PK, no non-constant default), `RENAME COLUMN` (3.25+), and `DROP COLUMN` (3.35+, with
restrictions — not if indexed, PK, or referenced). Everything else — type changes, constraint
changes, adding FKs — requires the documented 12-step **table rebuild** (create new, copy, drop
old, rename, re-create indexes, `PRAGMA foreign_key_check`).

Phase 1 ships without the rebuild: on SQLite, changes the native `ALTER` subset cannot express
classify as **Manual** (loud, with the rebuild named in the reason). Phase 2 implements the
rebuild as the SQLite backend for those changes. This keeps phase 1 honest on all three dialects
(nothing silently skipped) without gating the whole package on the rebuild's considerable testing
burden. Consumers whose SQLite is a dev/test mirror (the common case) mostly recreate those
databases anyway.

## 5. Applying: ordering, atomicity, the ledger

### 5.1 Ordering

Statements order by dependency, using `TableSchema::$foreignKeys` (the graph the UoW design also
uses): new tables and `ADD COLUMN`s before FKs that reference them; FK/index drops before the
column/table drops they guard; within a class, creations before drops. Cycles (mutually-referencing
tables) degrade to: all structural adds first, then all FK additions as a second wave — the
standard two-pass answer.

### 5.2 Atomicity is per-statement, not per-plan

PostgreSQL and SQLite have transactional DDL; **MySQL does not** (every DDL implicitly commits).
The design therefore does *not* promise an atomic converge anywhere — promising it only on some
backends would be a portability trap of exactly the kind the tri-dialect DoD exists to catch.
Instead:

- every planned statement is **individually idempotent to retry** — guarded by the same
  introspection that planned it (a re-run re-plans; already-applied changes simply vanish from the
  new plan). A converge interrupted mid-plan (crash, timeout) is resumed by running it again.
- the whole `apply()` runs inside `DbSession::withAdvisoryLock()` (per-dialect: `GET_LOCK` /
  `pg_advisory_lock`; SQLite's writer lock suffices), so two web requests converging concurrently
  — the WordPress reality — serialize instead of interleaving DDL.
- a statement failure **stops the run** (remaining statements are not attempted), records the
  failure in the ledger, and surfaces the error. Nothing rolls back what already applied — it
  didn't lose anything; the next run re-plans from live truth.

### 5.3 The ledger: an audit log, not a source of truth

One table, owned and auto-created by the companion (via the existing DDL producer — dogfood):

```
attrecord_schema_runs(id, started_at, finished_at, fingerprint_before, fingerprint_after,
                      statements_json, outcome ENUM('applied','partial','failed','noop'), error)
```

Its two jobs: **support forensics** ("what did the upgrade run on this site, and when?") — vital
for a plugin fleet you cannot shell into — and the **fingerprint fast path**: a canonical hash of
the desired model set. A consumer stores the last-converged fingerprint (the ledger's, or its own
option — InvFlux's `invflux_bootstrap_version` pattern) and skips even `plan()`'s introspection
when the code's fingerprint matches. What the ledger is **not**: consulted by the differ. Truth
about the live schema comes from the live schema; a ledger row saying "applied" proves nothing
after a host restore from backup. (This is the same "pure function of state, no change-log"
principle as UoW §3, applied to DDL.)

## 6. Data backfills: hooks, not migrations

Some schema changes are only *half* schema: `ADD COLUMN new_total NOT NULL` on a populated table
needs the values computed before tightening; a declared rename may need value rewriting. The
package does **not** grow a data-migration framework for these (§7). It exposes exactly one
mechanism: a planned change can carry a consumer-supplied **companion step** —

```php
$plan->withStep(after: 'ADD COLUMN orders.new_total', run: fn (DbSession $s) => /* backfill */);
```

— executed at that point in the apply order and recorded in the ledger run. The closure is the
consumer's code, in the consumer's repo, versioned with the Records it serves. If a consumer needs
ordered, reusable, cross-release data transformations, that is a real migrations framework and out
of scope by design.

## 7. Non-goals (the fence)

- **No migration-file chain, no version numbers on changes.** The desired state is versioned by
  the consumer's VCS already. One authority.
- **No down scripts.** Rollback = converge toward older Records, same rules (a destructive
  forward change is a destructive rollback too, and requires the same opt-in).
- **No auto-converge.** `apply()` is always an explicit call — never a bootstrap side effect,
  never triggered by a failed query. (The consumer may *choose* to call it from its upgrade hook;
  the package never does it for them.) The UoW's "no auto-flush" line, verbatim.
- **No inferred renames, no schema-similarity heuristics.** Declared or Destructive; nothing in
  between.
- **No dropping of unmanaged tables** — not even behind an option. The blast radius of a wrong
  guess (a shared WordPress database) is unbounded, and a consumer that truly wants a drop can
  write one line of SQL deliberately.
- **No online-DDL orchestration** (`pt-online-schema-change`, `gh-ost`, `ALGORITHM=INSTANT`
  negotiation). The plan is plain `ALTER` statements; a consumer operating at
  lock-a-huge-table scale brings their own tooling and feeds it the plan's SQL.
- **No data-migration framework** — §6's single hook is the whole concession.
- **No convergence of table options** (engine/charset/collation) — reported as Manual drift only.
  Host-managed, expensive to change, and wrong to churn on shared hosting.
- **Producer parity is the scope ratchet:** the differ manages exactly what `buildCreateTable()`
  emits — columns, defaults, generated columns, PK, unique keys, indexes, FKs. Features the
  producer doesn't model (CHECK, partitioning, FULLTEXT — see backlog) are invisible to the
  differ until the producer grows them; live-side instances of them are ignored, not dropped
  (they're unmanaged, like unmanaged tables).

If a requested feature needs one of the above, it is a sign the consumer needs Doctrine
Migrations / Phinx, not this package.

## 8. Why it ships as a companion package

The same two costs that sent the UoW out of core apply, plus a third specific to this feature:

- **Test combinatorics** — normalizers and differs are per-dialect × per-type × per-quirk; that
  matrix belongs in its own suite, not woven through core's.
- **Conceptual surface** — "plan/apply/converge/ledger" is operations vocabulary; core's pitch
  stays "small active record."
- **Blast radius** — core has never executed DDL against a live, populated schema; this package's
  whole job is to. A bug in core corrupts an object; a bug here drops a column. The trust
  boundary should be a package boundary: a consumer can pin, audit, or refuse
  `attrecord-migrations` independently of the library their runtime CRUD rides on.

### 8.1 The seams core must expose

Two, both small; they ship in core and are useful independently of the companion:

1. **Promote the DDL fragment builders to the `SqlDialect` interface.** `buildColumnLine(ColumnDefinition): string`
   and `buildForeignKeyLine(ForeignKeyDefinition): string` exist today as private methods inside
   each dialect's `buildCreateTable()`; add public `buildIndexClause()` / `buildUniqueKeyClause()`
   siblings (trivial extractions of the two inline `implode` lines). The companion composes
   `ALTER TABLE … ADD COLUMN {buildColumnLine(...)}` etc. from them — one rendering authority, so
   a column renders identically in CREATE and in ALTER, forever.
2. **`#[Column(renamedFrom: …)]`** — an inert `?string` on the attribute and `ColumnDefinition`
   (like `comment`). Core stores it; only the companion reads it.

Everything else the companion needs — `TableSchema`'s public readonly model, `quoteIdentifier`,
`toLiteral`, `withAdvisoryLock`, `buildCreateTable` for new tables and its own ledger — is already
public core surface. There is no hook *into* core behavior at all (unlike the UoW's finder seam):
core never calls the companion; the companion only reads core's model and session. That is the
cheapest possible coupling, and it is what makes this package safe to defer, version, or abandon
without touching core.

## 9. The consumer proof: what InvFlux retires

Every one of these hand-rolled sites is this package's job description (all present in
`invflux-storage-mysql` today):

| Hand-rolled site | Converge equivalent |
| --- | --- |
| `MysqlDomainStore::ensureSubjectStockConcernsBitsColumnType()` — `information_schema` probe → conditional `MODIFY COLUMN` | type diff on `bits`, Safe (widening) / Destructive (narrowing) |
| `MysqlOrderStore` `ADD COLUMN unit_price …` catch-duplicate-error idiom | desired-only column → `ADD COLUMN` (Safe) |
| `MysqlOrderStore::ensureColumn()` / `ensureIndex()` helpers (hand-built mini-differ) | the package, wholesale |
| `invflux_bootstrap_version` option gate | ledger fingerprint fast path (§5.3) |

The shape of the consumer call, in the plugin's upgrade hook:

```php
$plan = $migrator->plan(InvFluxSchema::recordClasses());
$migrator->apply($plan);                    // Safe ceiling — the plugin's unattended default
if ($plan->hasBeyondSafe()) {
    $this->flagForAdminReview($plan);       // Destructive/Manual: surfaced, never auto-run
}
```

## 10. Why this does not compromise the pillars

- **Legible performance** — `plan()` is one metadata read per table, gated by a fingerprint;
  `apply()` is the exact statement list you inspected, in the order shown. No hidden I/O anywhere
  (§7's no-auto-converge).
- **Static analysability** — the plan is a typed object; companion steps are typed closures;
  `renamedFrom` is a plain attribute field. No magic strings beyond SQL itself, which is the
  domain.
- **Tri-dialect honesty** — every behavior above states its per-dialect reality (transactional
  DDL, `ALTER` subsets, introspection source), and the phase-1 SQLite boundary is loud, not
  silent. A skipped backend is not a passing backend — same rule as the DoD, applied to design.
