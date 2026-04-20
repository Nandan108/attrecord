# WhereClause builder

`WhereClause` is an immutable, composable SQL fragment builder. It stores a semantic
tree of conditions and produces parameterised SQL at render time, quoting identifiers
for the target dialect only when `render($dialect)` is called.

---

## Quoting model

Column names passed to `WhereClause` factory methods are stored **unquoted**. Quoting
happens at `render($dialect)` time, so the same clause object works against any database.

```php
$c = WhereClause::where('status', 'pending');

$c->render(new MysqlDialect()); // (`status` = ?)
$c->render(new PgsqlDialect()); // ("status" = ?)
$c->render();                   // (status = ?)  ← unquoted, for debug only
```

`render(null)` (the default) produces unquoted SQL. It is useful for logging and
debugging but **must not** be used as live SQL.

When you use `Record::where()`, `Record::whereIn()`, or `Record::whereInTuples()`,
`find()` applies the class's configured dialect automatically — you never call
`render()` yourself.

---

## Factory methods

### `where($col, $value, $op = '=')`

Single-column comparison. `null` values produce `IS NULL` / `IS NOT NULL` rather
than a placeholder.

```php
WhereClause::where('status', 'pending')       // (status = ?)
WhereClause::where('total', 100, '>')         // (total > ?)
WhereClause::where('deleted_at', null)        // (deleted_at IS NULL)
WhereClause::where('deleted_at', null, '!=')  // (deleted_at IS NOT NULL)
```

### `whereIn($col, $values)` / `whereNotIn($col, $values)` — single column

```php
WhereClause::whereIn('status', ['pending', 'confirmed'])
// (status IN (?, ?))

WhereClause::whereNotIn('status', ['cancelled', 'refunded'])
// (status NOT IN (?, ?))

WhereClause::whereIn('status', [])
// (1 = 0)  — always false, matches no rows
```

### `whereIn($cols, $rows)` / `whereNotIn($cols, $rows)` — multi-column (row-value constructor)

Pass an array of column names and an array of value tuples. Delegates to
`whereInTuples()`. Supported by MySQL/MariaDB and PostgreSQL; not by SQLite.

```php
WhereClause::whereIn(['status', 'type'], [
    ['pending', 'order'],
    ['draft',   'quote'],
])
// ((status, type) IN ((?, ?), (?, ?)))
```

### `whereInTuples($cols, $rows)` / `whereNotInTuples($cols, $rows)`

Explicit multi-column form — identical to passing an array to `whereIn()` / `whereNotIn()`.

### `whereBetween($col, $low, $high)` / `whereNotBetween($col, $low, $high)`

```php
WhereClause::whereBetween('total', 10, 500)
// (total BETWEEN ? AND ?)  — params: [10, 500]

WhereClause::whereNotBetween('age', 18, 65)
// (age NOT BETWEEN ? AND ?)
```

### `whereLike($col, $pattern)` / `whereNotLike($col, $pattern)`

```php
WhereClause::whereLike('email', '%@example.com')
// (email LIKE ?)                         — MySQL / no-dialect
// ("email" LIKE ? ESCAPE '\')            — PostgreSQL (ESCAPE clause added automatically)

WhereClause::whereNotLike('name', 'test%')
// (name NOT LIKE ?)
```

**Escaping user input in patterns** — `%` and `_` are SQL wildcards. If you're
embedding user-supplied text in a pattern (e.g. a "contains" search), escape it first
with `SqlDialect::escapeLikeWildcards()` so those characters match literally:

```php
$safe = $dialect->escapeLikeWildcards($userInput);  // escapes %, _, and \
WhereClause::whereLike('name', '%'.$safe.'%');       // safe "contains" search
```

Both MySQL and PostgreSQL use `\` as the escape character. The necessary `ESCAPE '\'`
clause for PostgreSQL is appended to the SQL automatically by `render($dialect)` —
you only need to escape the value itself.

### `whereNot($clause)`

Wraps any clause in a `NOT (…)`.

```php
WhereClause::whereNot(WhereClause::where('active', false))
// (NOT (active = ?))
```

### `whereNone(...$clauses)`

Negates a disjunction — equivalent to `whereNot(whereAny(...))`. Requires at least
two clauses.

```php
WhereClause::whereNone(
    WhereClause::where('status', 'cancelled'),
    WhereClause::where('status', 'refunded'),
)
// (NOT ((status = ?) OR (status = ?)))
```

### `whereRaw($sql, $params = [])`

Escape hatch for conditions the builder cannot express natively: JSON operators,
subqueries, `REGEXP`, full-text `MATCH … AGAINST`, etc. The SQL fragment is used
verbatim — **the caller is responsible for quoting identifiers**.

```php
WhereClause::whereRaw('JSON_CONTAINS(`tags`, ?)', ['"featured"'])
// (JSON_CONTAINS(`tags`, ?))

WhereClause::whereRaw('MATCH(`title`) AGAINST (? IN BOOLEAN MODE)', ['php +mysql'])
```

The dialect passed to `render()` does not affect the output of a `whereRaw` node.

---

## Combinators

`andWhere()` and `orWhere()` are variadic — pass one or more clauses to combine them
in a single call. `whereAll()` and `whereAny()` are the static equivalents when there
is no natural "starting" clause (both require at least two arguments):

```php
use Nandan108\Attrecord\WhereClause as WC;

// Chain from a starting clause
WC::where('active', true)
    ->andWhere(WC::where('status', 'pending'));
// ((active = ?) AND (status = ?))

// Static form — no natural starting clause
WC::whereAll(
    WC::where('active', true),
    WC::where('status', 'pending'),
);
// ((active = ?) AND (status = ?))

// Three-way OR in one call
WC::where('status', 'pending')
    ->orWhere(
        WC::where('status', 'confirmed'),
        WC::where('status', 'processing'),
    );
// ((status = ?) OR (status = ?) OR (status = ?))

// Nested AND/OR
WC::where('active', true)
    ->andWhere(
        WC::where('total', 0, '>')
            ->orWhere(WC::where('flagged', true))
    );
// ((active = ?) AND ((total > ?) OR (flagged = ?)))
```

Instances are **immutable** — each combinator call returns a new `WhereClause`
without modifying the receiver.

---

## Using with `find()`

Pass a `WhereClause` anywhere `find()` accepts a WHERE string. The dialect is
applied automatically:

```php
$clause = WhereClause::where('status', 'pending')
    ->andWhere(WhereClause::where('total', 100, '>'));

$orders = Order::find($clause);
$orders = Order::find($clause, orderByLimit: 'ORDER BY total DESC LIMIT 10');
```

Mixing `whereRaw` with builder conditions works too — params are merged in order:

```php
$clause = WhereClause::where('active', true)
    ->andWhere(WhereClause::whereRaw('JSON_CONTAINS(`tags`, ?)', ['"featured"']));

Order::find($clause);
// WHERE ((`active` = ?) AND (JSON_CONTAINS(`tags`, ?)))
// params: [true, '"featured"']
```

---

## `params()`

Returns the bound values in positional order, matching the `?` placeholders produced
by `render()`. Dialect-independent.

```php
$c = WhereClause::where('status', 'pending')
    ->andWhere(WhereClause::whereIn('type', ['order', 'quote']));

$c->params(); // ['pending', 'order', 'quote']
```
