# Design note — attrecord will not auto-convert property names to column names

**Status:** Decided, 2026-05-25. Revisit only if a concrete pain point arises
that explicit `name:` overrides genuinely cannot solve.

attrecord maps each `#[Column]` property to exactly one SQL column. The
column name is either:

1. equal to the PHP property name (default — no conversion), or
2. set explicitly via `#[Column(name: '…')]`.

There is **no** `camelCase ↔ snake_case` auto-conversion mode, neither
default nor opt-in. This document explains why, so the question doesn't
get re-litigated each time a contributor with Laravel/Eloquent or Doctrine
background encounters the codebase.

---

## What auto-conversion would do

```php
// Hypothetical — NOT what attrecord does.
#[Table(name: 'orders', namingStrategy: SnakeCase::class)]
final class OrderRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned)]
    public int $customerId = 0;  // → derives column "customer_id"
}
```

The appeal is real: a Record that follows the camelCase-PHP /
snake_case-SQL convention uniformly would write `#[Column(...)]` with no
`name:` argument, and the column name would be derived from the property
name automatically. Doctrine ships `UnderscoreNamingStrategy`; Eloquent
ships snake-case mutators. Both reward conventional naming with less ceremony.

---

## Why attrecord rejects it anyway

### 1. Refactoring hazard — Rename Symbol becomes a schema migration

PHP IDEs (PhpStorm, VS Code with Intelephense) treat property rename as a
safe local refactor. "Rename Symbol" on `$customerId` → `$buyerId` updates
every reference in the codebase and is a normal, routine operation.

With auto-conversion, that routine rename silently changes the SQL column
name from `customer_id` to `buyer_id`. Next deploy, the SELECT/INSERT
references a column that doesn't exist in the live database. The migration
required to make this safe is invisible from the diff — there is no
attribute change to spot in code review.

With explicit `name:` overrides (or no override = property == column), the
column name lives in the attribute literally. Renaming the property doesn't
touch it. The IDE refactor is safe.

### 2. Grep-ability — the column name vanishes from the PHP source

`git grep customer_id` is the universal way developers (and AI agents)
locate everywhere a column is referenced: PHP code, migration SQL files,
log entries, test fixtures, slow-query reports. With auto-conversion,
the literal string `customer_id` does not appear anywhere in the PHP
source — only `customerId` does. To find a column reference you must
mentally apply the conversion algorithm.

This problem compounds with AI assistance: an AI agent reading the
codebase locates references by string match. The agent can run a single
`grep customer_id` and see every usage. With auto-conversion, the agent
has to know the convention, apply it in reverse, and grep for both forms
to be sure. That's a real friction tax on every code-comprehension step.

### 3. Two sources of truth for "what's the column name"

Without auto-conversion, the answer is one sentence: "The column name is
the value of `name:` on the `#[Column]` attribute, or the property name
when `name:` is omitted." That's it. Two lookup paths, both visible at
the property declaration site.

With auto-conversion, the answer becomes: "The column name is the value
of `name:` on the `#[Column]` attribute, or the result of applying the
table's naming strategy to the property name when `name:` is omitted,
unless the property name happens to already be snake_case in which case
the strategy is a no-op." Five clauses. Each combination has its own
edge cases (numeric suffixes? trailing underscores? legacy `URLPath`-style
initialisms that violate PSR-12?). Every edge case becomes a thing to
remember or look up.

---

## Why the ceremony objection doesn't bite hard

The argument *for* auto-conversion is "less ceremony — fewer `name: '…'`
arguments to write." In practice:

- **Most properties either match the column verbatim** (snake_case PHP
  property = snake_case column: `public int $customer_id` matches
  `customer_id`) **or need an explicit override anyway** (joining a
  third-party schema, or renaming a column without renaming the property).
- **The class of cases that benefit from auto-conversion** is exactly
  "property follows the convention AND the column follows the convention
  AND they should always stay in sync forever." That's a narrow band, and
  every Record in that band saves at most one short attribute argument
  per column.
- **A typical domain layer has tens of Records, not hundreds.** The
  `name: '…'` ceremony is bounded and reviewable in code review.

The PSR-12 angle does deflate the initialism counter-argument — `UrlPath`
maps cleanly to `url_path` for PSR-compliant code. So the rejection isn't
"auto-conversion is intrinsically broken." It's "the convenience is small
and the costs are diffused across IDE refactors, code-search, and the
mental model — places where the costs are noticed only when something
goes wrong."

---

## What you do instead

For a Record that uses camelCase properties against snake_case columns:

```php
#[Table(name: 'orders', primaryKey: 'order_id')]
final class OrderRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, name: 'order_id', autoIncrement: true)]
    public ?int $orderId = null;

    #[Column(ColumnType::BigIntUnsigned, name: 'customer_id')]
    public int $customerId = 0;

    #[Column(ColumnType::VarChar, length: 64, name: 'external_ref')]
    public ?string $externalRef = null;
}
```

For a Record that keeps property name = column name (no override needed):

```php
#[Table(name: 'orders')]
final class OrderRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $customer_id = 0;
}
```

Both are first-class. Both are explicit. Both grep cleanly.

---

## When to revisit this decision

Open a discussion (do **not** silently implement) only when:

- A real codebase has accumulated >50 Records all with mechanical
  `name: 'snake_case_of_property'` overrides and the duplication is
  measurably annoying — not theoretically annoying.
- Someone proposes a design that resolves the refactoring hazard
  (e.g. tooling that detects property renames in a `#[Column]` context
  and prompts for a migration), AND
- Someone proposes a design that resolves the grep-ability problem
  (e.g. a tool that emits a `// column: customer_id` adjacent comment
  generated from the convention).

Until both are credibly addressed, the decision stands.
