# attrecord

Lightweight PHP 8.1+ attribute-driven active-record layer.

- Declare schema with PHP attributes — no XML, no YAML, no separate migration files
- Dirty-tracking — `save()` only writes changed columns
- Bulk upsert via `RecordSet::saveAll()` with a single SQL statement
- Eager relation loading with no N+1 queries (`with()`)
- Deadlock-safe locking helpers (`LockTier`, `Transaction`)
- Three included `DbSession` adapters: PDO, mysqli, and WordPress `wpdb`
- Psalm-clean at level 1

---

## Installation

```bash
composer require nandan108/attrecord
```

Requires PHP 8.1+. No runtime dependencies.

---

## Quick start

### 1 — Define your records

```php
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Attribute\{Table, Column, Relation};
use Nandan108\Attrecord\Enum\{ColumnType, RelationType};

#[Table(name: 'orders')]
class Order extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    public string $status = 'draft';

    #[Column(ColumnType::Decimal, precision: 10, scale: 2, nullable: true)]
    public ?float $total = null;

    #[Column(ColumnType::DateTime, nullable: true)]
    public ?\DateTimeImmutable $placed_at = null;

    /** @var RecordSet<OrderLine>|null */
    #[Relation(RelationType::OneToMany, class: OrderLine::class, foreignKey: 'order_id')]
    public ?\Nandan108\Attrecord\RecordSet $lines = null;
}

#[Table(name: 'order_lines')]
class OrderLine extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $order_id = 0;

    #[Column(ColumnType::VarChar, length: 200)]
    public string $sku = '';

    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 1;

    #[Relation(RelationType::ManyToOne, class: Order::class, foreignKey: 'order_id')]
    public ?Order $order = null;
}
```

### 2 — Bootstrap once

```php
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Session\PdoDbSession;

$pdo  = new PDO('mysql:host=127.0.0.1;dbname=shop;charset=utf8mb4', 'user', 'pass');
$conn = new Connection(new PdoDbSession($pdo), new MysqlDialect());

Record::setConnection($conn);
```

Per-class override (e.g. a multi-tenant setup):

```php
Record::setConnection($tenantConn, forClass: Order::class);
```

### 3 — Use

```php
// INSERT
$order = new Order();
$order->status = 'pending';
$order->total  = 99.95;
$order->save();               // INSERT INTO `orders` SET ...
echo $order->id;              // auto-assigned PK

// SELECT by PK
$order = Order::getOne(42);          // ?Order
$order = Order::getOneOrFail(42);    // Order  (throws RecordNotFoundException if missing)
$order = Order::getOneOrNew(42);     // Order (new, unsaved instance if missing)

// UPDATE — only dirty columns
$order->status = 'confirmed';
$order->save();   // UPDATE `orders` SET `status` = ? WHERE `id` = ?

// No-op when clean
$order->save();   // returns false, no SQL

// DELETE
$order->delete();

// Reload from DB (e.g. after an external update)
$order->reload();
```

---

## Finders

```php
// All records (no WHERE)
$all = Order::find();

// With WHERE clause — positional params
$pending = Order::find('`status` = ?', ['pending']);

// With WHERE clause — named params
$recent  = Order::find('`placed_at` > :since', ['since' => '2024-01-01']);

// ORDER BY / LIMIT
$top10 = Order::find('`total` > ?', [100], 'ORDER BY `total` DESC LIMIT 10');

// First match or null
$draft = Order::findOne('`status` = ?', ['draft']);

// Count
$count = Order::countWhere('`status` = ?', ['pending']);

// Bulk delete
$deleted = Order::deleteWhere('`status` = ? AND `total` IS NULL', ['draft']);
```

### Convenience finders

Column names are automatically quoted by the class's configured dialect:

```php
// Single-column equality
$pending = Order::where('status', 'pending');

// Comparison operator
$large = Order::where('total', 100, '>');

// NULL check  (null value → IS NULL / IS NOT NULL)
$unplaced = Order::where('placed_at', null);

// IN list
$active = Order::whereIn('status', ['pending', 'confirmed']);
```

### WhereClause builder

For programmatic conditions, compose a `WhereClause` and pass it to `find()`.
Column names are stored unquoted and quoted for the target dialect at render time:

```php
use Nandan108\Attrecord\WhereClause as WC;

$clause = WC::where('status', 'pending')
    ->andWhere(
        WC::where('total', 100, '>')
            ->orWhere(WC::where('flagged', true))
    );

$orders = Order::find($clause);
```

`Record::where()` / `whereIn()` handle quoting automatically. When building
`WhereClause` directly, pass unquoted column names — quoting is applied by `find()`
via the class's configured dialect.

See [docs/where-clause.md](docs/where-clause.md) for the full reference: `whereIn`,
`whereInTuples`, `whereRaw`, variadic combinators, and the `render($dialect)` API.

---

## Dirty tracking

```php
$order = Order::hydrateFromArray(['id' => 1, 'status' => 'draft', 'total' => null, 'placed_at' => null]);

$order->isDirty();              // false — just loaded
$order->status = 'confirmed';
$order->isDirty();              // true
$order->isDirty('status');      // true
$order->isDirty('total');       // false

$order->dirtyFields();
// ['status' => ['draft', 'confirmed']]  (snapshot → current)
```

---

## RecordSet

`find()` returns a `RecordSet<T>` — a typed, iterable collection.

```php
$orders = Order::find('`status` = ?', ['pending']);

count($orders);             // Countable
foreach ($orders as $o) {} // Iterator

$orders->first();           // ?Order
$orders->last();            // ?Order

// Map a column to a flat array
$ids = $orders->pluck('id');   // list<mixed>

// Index by a unique column
$byId = $orders->recordsByKey('id');  // array<int|string, Order>

// Group by a column
$byStatus = $orders->recordsGroupedByKey('status');  // array<string, RecordSet<Order>>
```

### Bulk operations

```php
// Batch upsert — deadlock-safe 3-step strategy for all dirty records
$set = new RecordSet([$line1, $line2, $line3]);
$result = $set->saveAll();   // ?SaveResult — null when nothing to save

$result->inserted;      // rows newly written
$result->updated;       // rows overwritten
$result->total();       // inserted + updated
$result->insertedIds;   // list<int|string> — PKs of newly inserted auto-increment records

// Bulk delete
$deleted = $set->deleteAll();  // DELETE FROM … WHERE id IN (…)
```

**Notes on `saveAll()`:**

- Clean records are skipped automatically.
- `insertedIds` is populated for new (no-PK) records: via `RETURNING` on PostgreSQL, or via
  `lastInsertId()` + sequential range on MySQL/MariaDB. On MySQL/MariaDB clustered setups with
  non-sequential auto-increment, use individual `Record::save()` calls instead.
- For tables with natural (non-auto-increment) PKs, `saveAll()` performs a true upsert (PKs are
  already set on the PHP objects).

---

## Eager relation loading

Avoids N+1 with a single extra query per relation level:

```php
// One extra query per level
$orders = Order::find('`status` = ?', ['pending'])
    ->with('lines');          // SELECT … WHERE order_id IN (…)

foreach ($orders as $order) {
    foreach ($order->lines as $line) {
        echo $line->sku;
    }
}

// Dot-notation chains
$orders->with('lines.product');  // loads lines, then products for those lines
```

---

## Polymorphic relations

Polymorphic relations let one table reference rows from multiple other tables through a
type-discriminator column and a shared FK column.

```
tags
  id            bigint PK
  tagable_type  varchar   ← discriminator: 'order' | 'product' | ...
  tagable_id    bigint    ← FK to the matching table's PK
  name          varchar
```

### Declaring the schema

```php
// Parent side — Order has many Tags
#[Table(name: 'orders')]
class Order extends Record
{
    // …

    /** @var RecordSet<Tag>|null */
    #[Relation(RelationType::MorphMany, class: Tag::class,
        morphType: 'tagable_type', morphKey: 'tagable_id',
        morphValue: 'order')]
    public ?RecordSet $tags = null;

    // MorphOne: same as MorphMany but returns a single record or null
    #[Relation(RelationType::MorphOne, class: Tag::class,
        morphType: 'tagable_type', morphKey: 'tagable_id',
        morphValue: 'order')]
    public ?Tag $primaryTag = null;
}

// Child side — Tag belongs to a polymorphic parent
#[Table(name: 'tags')]
class Tag extends Record
{
    #[Column(ColumnType::VarChar, length: 50)]
    public string $tagable_type = '';

    #[Column(ColumnType::BigIntUnsigned)]
    public int $tagable_id = 0;

    #[Relation(RelationType::MorphTo,
        morphType: 'tagable_type',
        morphKey: 'tagable_id',
        morphMap: ['order' => Order::class, 'product' => Product::class])]
    public Order|Product|null $tagable = null;
}
```

`morphValue` can be a string or an integer — use integers when the discriminator column
is an FK into a type-lookup table (see [docs/polymorphic-relations.md](docs/polymorphic-relations.md)).

### Eager loading

```php
// Load orders with all their tags — one extra query
$orders = Order::find('`status` = ?', ['pending'])->with('tags');

foreach ($orders as $order) {
    foreach ($order->tags as $tag) {
        echo $tag->name;
    }
}

// Load tags with their polymorphic parent — one query per distinct type present
$tags = Tag::find()->with('tagable');

foreach ($tags as $tag) {
    // $tag->tagable is an Order or Product depending on tagable_type
}

// Chains work too: orders → tags → tagable (round-trip)
$orders->with('tags.tagable');
```

`with('tagable')` issues one `IN(…)` query per distinct type value present in the result
set — not one query per row.

Tags whose `tagable_type` has no entry in `morphMap` are silently skipped (property stays
`null`). This makes schema evolution safe: new type values added to the DB before the PHP
code is updated will not cause errors.

→ See [docs/polymorphic-relations.md](docs/polymorphic-relations.md) for schema design advice, trade-offs, and integer discriminator patterns.

---

## Transactions

```php
Order::transactional(function (Transaction $tx): void {
    $order = Order::getOne(42, forUpdate: true, tx: $tx);
    $order->status = 'shipped';
    $order->save();

    $line = new OrderLine();
    $line->order_id = $order->id;
    $line->sku = 'WIDGET-1';
    $line->save();
});
// Automatically committed; rolled back on exception
```

Nested `transactional()` calls are safe — only the outermost call issues `BEGIN` / `COMMIT` / `ROLLBACK`.

---

## Deadlock-safe locking

Declare a lock tier on each entity to enforce a consistent lock order across all code paths:

```php
use Nandan108\Attrecord\Attribute\LockTier;

#[Table(name: 'orders')]
#[LockTier(1)]
class Order extends Record { ... }

#[Table(name: 'order_lines')]
#[LockTier(2)]
class OrderLine extends Record { ... }
```

Inside a transaction, `getOne(..., forUpdate: true, tx: $tx)` registers the lock. Attempting to acquire a lower-tier lock after a higher-tier one throws `LockTierConflictException`, preventing deadlocks at the application level.

```php
Order::transactional(function (Transaction $tx): void {
    $order = Order::getOne(1, forUpdate: true, tx: $tx);      // tier 1 ✓
    $line  = OrderLine::getOne(5, forUpdate: true, tx: $tx);  // tier 2 ✓
    // OrderLine::getOne after Order::getOne is safe (2 > 1)

    // Reversed order would throw LockTierConflictException
});
```

---

## DB session adapters

All three implement `DbSession` and can be swapped without changing application code.

### PDO (recommended for new projects)

```php
use Nandan108\Attrecord\Session\PdoDbSession;

$pdo  = new PDO('mysql:host=127.0.0.1;dbname=shop', 'user', 'pass');
$conn = new Connection(new PdoDbSession($pdo), new MysqlDialect());
```

### mysqli

```php
use Nandan108\Attrecord\Session\MysqliDbSession;

$mysqli = new mysqli('127.0.0.1', 'user', 'pass', 'shop');
$conn   = new Connection(new MysqliDbSession($mysqli), new MysqlDialect());
```

### WordPress wpdb

```php
use Nandan108\Attrecord\Session\WpDbSession;

global $wpdb;
$conn = new Connection(new WpDbSession($wpdb), new MysqlDialect());
Record::setConnection($conn);
```

`WpDbSession` converts attrecord's `?` placeholders to `%s` for `wpdb::prepare()` and escapes existing `%` in LIKE clauses automatically.

---

## Unit testing with CapturingDbSession

`src/Test/CapturingDbSession.php` records SQL without touching a database:

```php
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;

$session = new CapturingDbSession();
Record::setConnection(new Connection($session, new MysqlDialect()));

$order = new Order();
$order->status = 'pending';
$order->save();

assertStringContainsString('INSERT INTO `orders`', $session->lastSql());
assertSame(['pending'], $session->lastParams());

// Control the returned PK
$session->setNextInsertId(100);
$order2 = new Order();
$order2->status = 'draft';
$order2->save();
assertSame(100, $order2->id);

// Full call log
$session->allCalls();  // list<array{sql: string, params: list<scalar|null>}>
$session->reset();     // clear log
```

---

## Column types

| PHP type             | `ColumnType` cases                                                                                            |
| -------------------- | ------------------------------------------------------------------------------------------------------------- |
| `int`                | `TinyInt`, `SmallInt`, `MediumInt`, `Int`, `BigInt`, `*Unsigned`, `Year`, `Bit`                               |
| `bool`               | `Bool`                                                                                                        |
| `float`              | `Float`, `Double`, `Decimal`                                                                                  |
| `string`             | `Char`, `VarChar`, `TinyText`, `Text`, `MediumText`, `LongText`, `Json`, `Enum`, `Set`, `Binary`, `VarBinary` |
| `\DateTimeImmutable` | `Date`, `DateTime`, `Timestamp`                                                                               |

**Column options:**

```php
#[Column(
    type:          ColumnType::VarChar,
    nullable:      true,    // allows NULL; PHP property becomes ?string
    autoIncrement: true,    // skipped in INSERT/UPDATE; PK assigned after INSERT
    trimOnSave:    true,    // trim whitespace on save; also suppresses dirty-detection for whitespace-only changes
    length:        255,     // informational; not enforced by this library
    precision:     10,      // for Decimal
    scale:         2,       // for Decimal
)]
```

---

## Relation types

| `RelationType`     | FK location                              | PHP property type | Required parameters                            |
| ------------------ | ---------------------------------------- | ----------------- | ---------------------------------------------- |
| `OneToMany`        | Related table has FK pointing here       | `?RecordSet<T>`   | `class`, `foreignKey`                          |
| `ManyToOne`        | This table has FK pointing to related PK | `?T`              | `class`, `foreignKey`                          |
| `OneToOne`         | This table has FK                        | `?T`              | `class`, `foreignKey`                          |
| `OneToOneReversed` | Related table has FK                     | `?T`              | `class`, `foreignKey`                          |
| `MorphMany`        | Related table has type+FK pointing here  | `?RecordSet<T>`   | `class`, `morphType`, `morphKey`, `morphValue` |
| `MorphOne`         | Related table has type+FK pointing here  | `?T`              | `class`, `morphType`, `morphKey`, `morphValue` |
| `MorphTo`          | This table has type+FK columns           | `?T` (union)      | `morphType`, `morphKey`, `morphMap`            |

```php
// Standard relation
#[Relation(
    type:       RelationType::OneToMany,
    class:      OrderLine::class,   // target Record subclass
    foreignKey: 'order_id',         // FK column name
    localKey:   'id',               // optional; defaults to this table's PK
)]

// Polymorphic parent
#[Relation(
    type:       RelationType::MorphMany,
    class:      Tag::class,
    morphType:  'tagable_type',     // type-discriminator column on the related table
    morphKey:   'tagable_id',       // FK column on the related table
    morphValue: 'order',            // value stored in morphType for this class (string or int)
)]

// Polymorphic child
#[Relation(
    type:      RelationType::MorphTo,
    morphType: 'tagable_type',      // local type-discriminator column
    morphKey:  'tagable_id',        // local FK column
    morphMap:  ['order' => Order::class, 'product' => Product::class],
)]
```

---

## Running tests

```bash
# Unit tests (no DB needed)
composer test -- --testsuite unit

# Integration tests (requires MariaDB + PostgreSQL)
docker compose up -d
composer test -- --testsuite integration

# All tests
composer test
```

Environment variables for integration tests (defaults shown):

```
# MySQL / MariaDB
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=attrecord_test
DB_USER=root
DB_PASS=root

# PostgreSQL (tests skipped if unavailable)
PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DB=attrecord_test
PGSQL_USER=postgres
PGSQL_PASS=postgres
```

---

## License

MIT
