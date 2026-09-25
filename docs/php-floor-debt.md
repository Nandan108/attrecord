# attrecord — debt held by the PHP floor

**Current floor: `php: ^8.1`.** CI's lowest row is PHP 8.1 (with MySQL 8.0 and PostgreSQL 14), so
8.1 is tested rather than merely claimed.

This file lists what the floor *costs us today* — workarounds written only because 8.1 must keep
parsing and passing. Raising the floor should be a checklist, not an archaeology exercise: without
this, the workarounds stay forever, because each one looks like a deliberate style choice to whoever
reads it next.

**Not in scope.** Things 8.1 simply cannot express that we do not want anyway, and constraints that
have already been paid off — a workaround that was *removed* is history, and history lives in git.
Nor is this a wish list: an entry earns its place by naming code that exists now and would change.

**Add an entry the moment you write the workaround**, with the file and the reason. That is the only
moment when the cost is obvious; afterwards it reads as ordinary code. The greppable tells are
`PHP_VERSION_ID` and the phrase "below PHP 8." — but a grep finds only what somebody thought to
comment, which is why the list is kept by hand.

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

## Watch, not debt

**PHP 9 turns dynamic property creation into an `Error`** (deprecated in 8.2). attrecord no longer
creates one — `RecordSet` assigning an attribute key that names no column was fixed precisely
because it was both a silent-typo bug and an upgrade blocker. Recorded here only so a future reader
does not go looking: there is nothing left to pay.
