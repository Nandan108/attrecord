# attrecord — debt held by a version floor

Workarounds that exist **only** because some minimum version must keep working — the language's or
an engine's. Raising a floor should be a checklist, not an archaeology exercise: without this list
the workarounds stay forever, because each one reads as a deliberate style choice to whoever sees it
next.

## Declared floors

| Floor | Minimum | Where it is declared | Tested? |
|---|---|---|---|
| PHP | `^8.1` | `composer.json` | yes — CI's lowest row is 8.1 |
| MySQL | 8.0 | prose only | yes — `mysql:8.0` in the matrix |
| MariaDB | 10.11 | prose only | yes — `mariadb:10.11` in the matrix |
| PostgreSQL | 14 | prose only | yes — `postgres:14` in the matrix |
| SQLite | 3.35 | prose only | **no** — whatever the runner's PHP ships |

**Only the PHP floor is machine-checkable.** `composer.json` can require a PHP version and cannot
require a database one, so every engine floor is a claim in prose that nothing enforces — which is
how the SQLite one managed to be stated as two different numbers in six places at once (see its
section). The table above is the single place to correct.

**These floors drift *upward*, as support windows close** — so an entry here is a cost that expires
on its own, and "raise the floor and delete this" is eventually free. Worth stating because it is
not universal: a floor that was *deliberately lowered* to reach more installs buys reach with every
entry below it, and there "raise it and delete this" is a product decision rather than a maintenance
chore. The InvFlux adapter's PHP floor is that kind (lowered 8.3 → 8.1 for wp.org hosts); attrecord
has none today. If one is ever lowered here, say so in this table, because the reader's default
assumption is drift.

## What belongs here, and what does not

The axis is **"a version raise deletes this code"**, not "this is about PHP" or "this is about a
database".

- **In:** code shaped by a floor, where bumping that floor removes or simplifies it.
- **Out:** *permanent* divergence between engines — `GREATEST` propagating NULLs on MySQL and SQLite
  but not PostgreSQL, `X'hex'` vs `'\x…'::bytea`, backticks vs double quotes. No version raise ever
  fixes those; PostgreSQL is not going to change `GREATEST`. They live in **CLAUDE.md's
  cross-dialect gotchas list**, and copying them here would produce exactly the second, staler set
  of docs that [backlog.md](backlog.md) warns about.
- **Out:** anything already paid off. A workaround that was *removed* is history, and history lives
  in git.

An entry earns its place by naming code that exists now and would change. This is not a wish list.

**Add an entry the moment you write the workaround**, with the file and the reason — that is the
only moment when the cost is obvious. The greppable tells are `PHP_VERSION_ID` and phrases like
"below PHP 8." or "≥ 3.", but a grep finds only what somebody thought to comment, which is why the
list is kept by hand.

---

## Clears at PHP 8.2

### Trait constants → two static methods

`tests/Integration/Cases/BinaryPredicateCases.php` needs two 16-byte owner ids shared across its
cases. They want to be `private const`, but **a trait cannot declare a constant before 8.2**, so
they are `private static function ownerA()` / `ownerB()` returning a literal.

*On an 8.2 floor:* make them constants and drop the two methods. Behaviour is identical; only the
noise goes.

### The duplicate-enum-value fixture, and the skip that guards it

`BitmaskCaster` refuses an enum whose cases share a bit value, and
`FlagSetCasterTest::testBitmaskRejectsDuplicateBit()` proves it. The fixture that provokes it,
`tests/Fixtures/FlagDupBitEnum.php`, declares two cases with the same value — which **8.1 rejects at
compile time**, so it cannot be parsed there at all.

Two contortions follow, and they are load-bearing together:

- the fixture lives in **its own file** so PSR-4 loads it lazily and 8.1 never parses it; and
- the test **skips below 8.2** (`PHP_VERSION_ID < 80200`), so the fixture is never autoloaded there.

*On an 8.2 floor:* drop the skip, and the fixture may move inline to the test if that reads better.
Note the guard is then exercised on every row of the matrix rather than most of them — which is the
real gain, since a skipped row is not a passing row.

### `#[Column(default:)]` — enum case vs `->value`

`src/Attribute/Column.php` accepts a backed-enum case as a column default and unwraps it to the
backing value. Its docblock notes this is "the only form usable below PHP 8.2", because
`Status::Active->value` is a property fetch and **not a valid constant expression before 8.2** — so
writing it inside an attribute makes the whole class unparseable on 8.1.

*On an 8.2 floor:* nothing to remove. The enum-case form stays, because it is the better API — it
keeps the attribute tied to the vocabulary that owns the value instead of restating a literal. What
changes is the *reason*: it stops being a necessity and becomes a preference, so the docblock's
"only form usable" clause must be corrected or it becomes a false claim about PHP.

---

## Clears at PHP 8.3

### `#[\Override]` is decorative on two of the four tested versions

The attribute is used throughout `src/`, and it was introduced in **8.3**. Below that it is an
unknown attribute: harmless, never resolved, and enforcing nothing. So on 8.1 and 8.2 — half the CI
matrix — a method that claims to override something and does not would pass.

*On an 8.3 floor:* the annotations already written start being checked everywhere, with no edit
needed. Worth knowing rather than doing: the benefit is real but arrives by itself, and until then
`#[\Override]` should not be trusted as the thing that catches a renamed parent method.

### Typed class constants

8.3 allows `public const string DEFAULT_ENGINE = 'InnoDB';`. Candidates are the small set of public
constants on the dialects and `TableSchema::MAX_IDENTIFIER_LENGTH`.

*On an 8.3 floor:* optional, low value — these are private-ish knobs with obvious literal types, and
psalm already infers them. Listed so the decision is made once rather than re-argued.

---

---

## Clears when MariaDB gains `EXCLUDED`

### `VALUES(col)` is kept for the whole MySQL family

The bulk upsert refers to the incoming row as `VALUES(col)` on MySQL *and* MariaDB.
**MySQL deprecated that spelling in 8.0.20** in favour of a row alias, and PostgreSQL and SQLite use
`EXCLUDED.col` — so ours is the one form that is deprecated on the engine it was written for.

It stays because **MariaDB supports no alternative**: it has neither the 8.0.20 row alias nor
`EXCLUDED`, and one emitter serves both engines. So this is debt held by MariaDB's floor, not
MySQL's, and MySQL's deprecation cannot clear it.

*When MariaDB supports `EXCLUDED` and that version becomes our floor:* `MysqlDialect::incomingRef()`
can return the modern form, and the deprecation note in the cross-dialect gotchas list goes with it.
Until then, `VALUES(col)` is correct and should not be "fixed".

---

## Clears at MySQL 8.0.16

### A CHECK constraint is not enforced on 8.0.0–8.0.15

MySQL **parses and silently ignores** `CHECK` before 8.0.16; MariaDB enforces from 10.2.1;
PostgreSQL and SQLite always do. Our floor is MySQL 8.0, which includes the ignoring range, so a
`#[Check]` on such a server is decoration — the DDL applies, nothing is validated, and no error says
so.

**This has a consumer with live exposure, found by the core-engine lane 2026-09-25 and verified
here.** InvFlux declares five `#[Check]` constraints, all of them structural domain invariants:

- `invflux-core` `Order/OrderLine.php` — `ref_or_parent`
- `invflux-core` `Subject/Subject.php` — `tracking_unit_only`, `batch_has_parent`,
  `aggregate_is_root`, `cost_grain_needs_tracking`

and the adapter's declared floor is `DbVersionCheck::MIN_MYSQL_VERSION = '8.0.0'` — *inside* the
ignoring range. So on MySQL 8.0.0–8.0.15 those five invariants are absent while the schema reports
itself satisfied. **MariaDB is unaffected**: that floor is `MIN_MARIADB_VERSION = '10.6.0'`, above
the 10.2.1 enforcement point. The gap is MySQL-only and 15 patch releases wide.

*Correcting what this entry said before:* there **is** a grep for it — `git grep '#\[Check('` — it
simply has to be run in the *consumer*, because attrecord provides the attribute and never uses it.
An entry about a library feature should name where the feature is *used*, not only where it is
defined.

*The lever is not here either.* Raising `MIN_MYSQL_VERSION` to `8.0.16` is the adapter's decision to
make and costs it whatever hosts sit in those 15 releases. attrecord's part is to state the
mechanism precisely; the consumer's copy of this doc names the owner and the trade.

---

## Clears at SQLite 3.35 — **now the declared floor, previously stated as 3.33**

Two features set the SQLite minimum and the floor is the later of them:

- **`UPDATE … FROM`** (3.33, 2020-08) — the bulk-upsert join form, see
  [arch-bulk-update-scaling.md](arch-bulk-update-scaling.md).
- **`RETURNING`** (3.35, 2021-03) — reading generated PKs back, so a multi-row `upsertAll()` yields
  every inserted id instead of only the last rowid.

**This was inconsistent until 2026-09-25.** Six places said "requires SQLite >= 3.33" while
separately noting that `RETURNING` needs 3.35, and nobody drew the conclusion. The gap was not
theoretical: `SqliteDialect::supportsReturning()` answers `true` unconditionally rather than
sniffing the library, so on 3.33 or 3.34 the package installed, the bulk upsert worked, and every
read-back path failed as a syntax error — on a version that satisfied the documented floor.

*Nothing to clear here now;* it is recorded because the resolution is the kind that silently
regresses. The rule it leaves behind: **the floor is the maximum over the features we use, and a
dialect predicate that does not sniff turns a soft minimum into a hard one.** Anyone pinned below
3.35 can subclass `SqliteDialect` and override `supportsReturning()`, which is one of its four
extension points.

Other SQLite version facts that are *not* debt, because they sit well under the floor and nothing is
shaped around them: row-value `IN` (3.15), `ON CONFLICT DO NOTHING` (3.24), `RENAME COLUMN` (3.25),
`DROP COLUMN` (3.35).

---

## Settled — do not re-litigate

Entries whose answer is "this costs nothing, stop checking". They are here because each one *looks*
like debt and gets re-opened every few months by whoever notices it next; a line here is cheaper
than re-deriving the answer. Distinct from **out of scope** above: these are in scope and already
paid.

**The floor is already above what the feature needs.** Row-value `IN` (SQLite 3.15),
`ON CONFLICT DO NOTHING` (3.24), `RENAME COLUMN` (3.25) all sit well under the 3.35 floor. Nothing
is shaped around them and no guard is needed.

**The code was changed, so the constraint no longer bites.** PHP 9 turns dynamic property creation
into an `Error` (deprecated in 8.2), and attrecord no longer creates one — `RecordSet` assigning an
attribute key that names no column was fixed precisely because it was both a silent-typo bug and an
upgrade blocker. Nothing left to pay.
