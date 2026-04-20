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
// Batch upsert — one SQL statement for all dirty records
$set = new RecordSet([$line1, $line2, $line3]);
$set->saveAll();   // INSERT … SELECT … ON DUPLICATE KEY UPDATE

// Bulk delete
$deleted = $set->deleteAll();  // DELETE FROM … WHERE id IN (…)
```

**Notes on `saveAll()`:**
- Clean records are skipped automatically.
- For tables with auto-increment PKs, new records are inserted but their PKs are **not** assigned back to the PHP objects. Use `Record::save()` when you need the new PK.
- For tables with natural (non-auto-increment) PKs, `saveAll()` performs a true upsert.

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

| PHP type             | `ColumnType` cases                                                   |
|----------------------|----------------------------------------------------------------------|
| `int`                | `TinyInt`, `SmallInt`, `MediumInt`, `Int`, `BigInt`, `*Unsigned`, `Year`, `Bit` |
| `bool`               | `Bool`                                                               |
| `float`              | `Float`, `Double`, `Decimal`                                         |
| `string`             | `Char`, `VarChar`, `TinyText`, `Text`, `MediumText`, `LongText`, `Json`, `Enum`, `Set`, `Binary`, `VarBinary` |
| `\DateTimeImmutable` | `Date`, `DateTime`, `Timestamp`                                      |

**Column options:**

```php
#[Column(
    type:          ColumnType::VarChar,
    nullable:      true,    // allows NULL; PHP property becomes ?string
    autoIncrement: true,    // skipped in INSERT/UPDATE; PK assigned after INSERT
    trimOnSet:     true,    // trim() on hydration
    length:        255,     // informational; not enforced by this library
    precision:     10,      // for Decimal
    scale:         2,       // for Decimal
)]
```

---

## Relation types

| `RelationType`      | FK location                              | PHP property type |
|---------------------|------------------------------------------|-------------------|
| `OneToMany`         | Related table has FK pointing here       | `?RecordSet<T>`   |
| `ManyToOne`         | This table has FK pointing to related PK | `?T`              |
| `OneToOne`          | This table has FK                        | `?T`              |
| `OneToOneReversed`  | Related table has FK                     | `?T`              |

```php
#[Relation(
    type:       RelationType::OneToMany,
    class:      OrderLine::class,   // target Record subclass
    foreignKey: 'order_id',         // FK column name
    localKey:   'id',               // optional; defaults to this table's PK
)]
```

---

## Running tests

```bash
# Unit tests (no DB needed)
composer test -- --testsuite unit

# Integration tests (requires MariaDB)
docker compose up -d
composer test -- --testsuite integration

# All tests
composer test
```

Environment variables for integration tests (defaults shown):

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=attrecord_test
DB_USER=root
DB_PASS=root
```

---

## License

MIT
