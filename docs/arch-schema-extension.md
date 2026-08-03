# Extending a Record's table from another package

How a package that does not own a Record can add columns to its table, and have those columns
created and converged like any other.

This is the natural follow-on question once a project declares its schema as Records and converges
it with [attrecord-migrations](arch-migrations.md): *"then how do other packages extend it?"* The
answer rests on four properties of attrecord, so it belongs here rather than in any one consumer's
documentation. What a host does with these patterns — the hook it exposes, which pattern it makes
the default, its policy when an extension goes away — is the host's design, not attrecord's.

Throughout, **host** means the package owning the Record, and **extender** a package adding to it.

---

## 1. The default: don't. Use a separate table.

An extender declaring its own Record, for its own table, keyed to the host's row, composes without
limit: it keeps fully typed CRUD through its own class, is unaffected by other extenders, and
uninstalls by dropping a table it owns. The cost is a join.

Prefer it. The patterns below couple an extender to a table it does not own, and everything after
this section is the price of that coupling. Reach for them when the join is genuinely the problem —
a hot read path — and not before.

---

## 2. Subclass the Record

An extender subclasses the host's Record and declares its columns as ordinary properties:

```php
#[Table(name: 'articles')]          // the same table as the host's Record
final class RankedArticle extends Article
{
    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $rank_score = 0;
}
```

The extender then reads and writes through `RankedArticle::find(…)`, which returns its own class
with its own columns typed. No dynamic properties, no `__get()`, and static analysis is preserved
end to end.

**Separate the two jobs:** the *host's registry* decides what DDL exists, and *subclasses* provide
typed access. Convergence should plan from the host's Record plus the union of registered deltas —
never from a subclass, so no subclass is "authoritative" and two extenders cannot disagree about
the table.

### The four properties this rests on

All four are verified against attrecord, not assumed:

| property | consequence |
| --- | --- |
| A subclass Record inherits its parent's columns and emits the enriched DDL | the delta is computable, and a subclass fully describes the table as that extender sees it |
| `find()` instantiates `new static()` | `Sub::find(…)` already returns the subclass — an extender needs no special read API |
| A narrow class writing to a wider table emits `SET` for **its own columns only** | one extender's write cannot clobber another's column |
| Hydration ignores columns the class does not declare | `SELECT *` returning every extender's columns is harmless, and no dynamic property leaks |

### The rule that makes extenders mutually invisible

> **Every added column MUST have a default, or be nullable.**

Not stylistic. A narrow class's `INSERT` omits every foreign column, so a `NOT NULL` column with
no default means extender B's mere presence breaks extender A's inserts — the exact coupling this
design exists to prevent. A host should enforce it at registration rather than document it.

---

## 3. Compute deltas against the root, not the immediate base

An extender may subclass another extender (`B extends A extends Host`). The delta to add to the
converged schema is `B's columns − Host's columns`, **not** `B's columns − A's columns`.

The difference shows when A's registration has not run:

- base-delta yields `{b_bar}`, so the converged table lacks `a_foo` — which `B` declares, and
  therefore reads and writes. B is broken by A's absence.
- root-delta yields `{a_foo, b_bar}`, so B is self-sufficient.

When both register, the deltas overlap on `a_foo` — but *identically*, since both derive it from
the same class. Hence:

> A column name appearing in more than one delta is allowed **iff the definition is identical**
> (compare the rendered column line, not the object). Differing definitions are a real collision
> and must throw.

That one rule permits chains, forbids two unrelated extenders fighting over `status`, and makes
registration order irrelevant.

### Guards a host should apply

All mechanically checkable, so none of this need be convention:

1. the class subclasses a Record the host manages, and declares the same `#[Table(name:)]`;
2. every added column has a default or is nullable (§2);
3. no name collision except an identical redeclaration (above);
4. added names carry the extender's own prefix — `TableSchema::extendedWith()` already rejects a
   clash with a name the class *declares*, but cannot know about a clash between two extenders;
5. the base class exists. `B extends A` cannot autoload when A is **uninstalled** — deactivation is
   harmless, since the files still load — so a missing base must skip the extension with a
   diagnostic rather than fatal.

---

## 4. Limits

- **Diamonds are impossible.** An extender wanting the columns of two independent extensions cannot
  subclass both; PHP stops it before any guard does. Such an extender falls back to §1.
- **A withdrawn extension leaves its column behind.** Once it stops registering, its column is
  live-but-undeclared, which the differ classifies `Destructive`. At a Safe ceiling that is
  reported and never applied — data is not at risk — but the drift report stays noisy until
  someone acts. What *should* happen then is a host policy question, not something attrecord
  decides.
- **Relation loading hydrates the relation's declared target.** `load('articles')` yields
  `Article`, not `RankedArticle`; an extender needing its own columns there must re-query through
  its own class.

---

## 5. Rejected alternatives

Recorded because each was reached by argument rather than being obviously wrong.

- **Dynamic properties** — a `$_dynamic` bag plus `__get()`, for columns with no PHP property.
  Rejected: it returns `mixed` and destroys the static checkability that is attrecord's point —
  the same argument that keeps casters out of `set()` — and it reopens the dynamic-property hole
  `set()` was tightened to close. §2 gets typed access without it. Should a case ever demand it,
  an explicit `extra('name')` accessor beats `__get()`: it does not pretend to be a property, and
  it concentrates the honest `mixed` at one call site.
- **A free-form `modifySchema(Class, fn (TableSchema $s): TableSchema => …)`.** Rejected: a
  callback returning an arbitrary schema can *remove* or narrow a column on a table it does not
  own, silently proposing a destructive change against someone else's data. Additive-by-
  construction beats additive-by-documentation.
- **A `return_as:` parameter on read methods** (`Article::find(…, return_as: RankedArticle::class)`).
  Rejected as redundant: `find()` does `new static()`, so `RankedArticle::find(…)` already returns
  the subclass with its columns. It would also not have touched the single-inheritance limit in §4,
  which is what actually constrains this design.

---

## 6. See also

- [arch-migrations.md](arch-migrations.md) — the convergence design these patterns extend.
- [ddl-generation.md](ddl-generation.md) — `TableSchema::extendedWith()`, the derivation a host
  uses to fold deltas into a schema.
