# attrecord

[![CI](https://github.com/Nandan108/attrecord/actions/workflows/ci.yml/badge.svg)](https://github.com/Nandan108/attrecord/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/nandan108/attrecord/branch/main/graph/badge.svg)](https://codecov.io/gh/nandan108/attrecord)
[![Packagist Version](https://img.shields.io/packagist/v/nandan108/attrecord)](https://packagist.org/packages/nandan108/attrecord)
[![PHP Version](https://img.shields.io/packagist/php-v/nandan108/attrecord)](https://packagist.org/packages/nandan108/attrecord)
[![License](https://img.shields.io/packagist/l/nandan108/attrecord)](LICENSE)

Lightweight PHP 8.1+ attribute-driven active-record layer.

- Declare schema with PHP attributes — no XML, no YAML, no separate migration files
- **Emit `CREATE TABLE` directly from the attributes** — single source of truth for column type, defaults, unique keys, indexes, and FK constraints; MySQL/MariaDB, PostgreSQL **and** SQLite
- **Dialect-portable** — MySQL/MariaDB, PostgreSQL, and SQLite share one code path for CRUD, batch upserts, eager loading, and DDL; binary columns bind correctly on all three (PG `bytea` / SQLite `BLOB` included). Advisory locks are available on MySQL/MariaDB and PostgreSQL
- **camelCase PHP / snake_case SQL** via per-column `name:` override (no auto-conversion — [decision documented](docs/design-note-no-name-auto-conversion.md))
- Dirty-tracking — `save()` only writes changed columns
- Column casting — map columns to value objects / JSON / custom types via `#[Cast]` attributes ([docs](docs/column-casting.md))
- Bulk upsert via `RecordSet::saveAll()` with a single SQL statement — optionally chunked (`saveAll(chunkSize:)`) for very large, resumable batches
- Optional automatic retry of transient transaction conflicts (deadlock / lock-wait / serialization / `SQLITE_BUSY`) via the `RetryingDbSession` decorator
- Relation loading with no N+1 queries — `load()` / `loadMissing()` (variadic, shared-prefix); nine
  relation types incl. **many-to-many** (pivot) and **has-many-through**
- Lifecycle hooks — `beforeSave()`/`afterSave()`, `beforeDelete()`/`afterDelete()`, `afterLoad()`
- Auto-managed timestamps via `#[CreatedAt]` / `#[UpdatedAt]`
- find-or-create ergonomics — `firstOrNew()` / `findOrCreate()` / `updateOrCreate()` (array-match)
- Domain invariants enforced at assignment and save time via a `validate()` hook
- Deadlock-safe locking helpers (`LockTier`, `LockSet`, `Transaction`) + advisory locks
- Unique-key aware upserts — single (`upsertByUniqueKey`) and bulk (`RecordSet::upsertAllByUniqueKey`), with an optional **auto-increment-burn-free** mode; plus `updateByUniqueKey`
- Constraint-only foreign keys via `#[ForeignKey]` — declare an FK whose target has no Record (or that you don't want to hydrate)
- Three included `DbSession` adapters: **PDO** (works with MySQL, MariaDB, PostgreSQL, and SQLite), plus MySQL/MariaDB-only **mysqli** and WordPress **`wpdb`**
- Psalm-clean at level 1

---

## How it compares

attrecord sits in a deliberately narrow spot — a lean, standalone Active Record — rather than
competing head-on with the full-scope ORMs. This table is here to help you *place* it, not to crown
a winner: Doctrine and Eloquent are mature, battle-tested, and far larger in scope and ecosystem.
[php-activerecord](https://github.com/php-activerecord/activerecord) — the long-standing Rails-style
port — is the closest sibling by pattern and lean/zero-dependency niche; it's included to show where
attrecord's *typed, schema-authoring, contention-hardened* design diverges from the classic
*dynamic, DB-introspecting* Active Record.

|  | **attrecord** | **php-activerecord** | **Doctrine ORM** | **Eloquent** |
| --- | --- | --- | --- | --- |
| Pattern | Active Record | Active Record | Data Mapper (identity map + unit of work) | Active Record |
| Framework coupling | Standalone, framework-agnostic | Standalone, framework-agnostic | Standalone (Symfony-friendly) | Laravel-native (standalone via `illuminate/database`) |
| Runtime dependencies | **None** | **None** (PDO ext) | Several (DBAL, …) | `illuminate/*` |
| Install footprint (`vendor/`) | **1 package · ~300 KB** | 1 package · ~280 KB | ~22 packages · ~1.6 MB | ~26 packages · ~2.3 MB |
| Schema mapping | PHP 8 attributes only | **Introspected from the live DB** (no code mapping) | Attributes / XML / YAML | Conventions (schema lives in migrations, not the model) |
| Column access / typing | **Typed properties** (psalm-checked) | Dynamic `__get`/`__set` (magic) | Typed properties | Dynamic `$attributes` (magic) |
| Schema changes | Emits `CREATE TABLE` from attributes; forward migrations via a planned opt-in add-on (not in core) | None — the DB *is* the source; no DDL/migrations | `doctrine/migrations` | Laravel migrations |
| Query building | Finders + immutable `WhereClause` + `RawSql` | Dynamic finders + string conditions (`find_by_x`) | DQL + QueryBuilder | Fluent query builder |
| Relations | Imperative `load()` / `loadMissing()`; incl. polymorphic, **many-to-many**, **has-many-through**; no lazy graph / identity map | `has_many`/`belongs_to`/HABTM/`through` + eager loading | Full graph: lazy loading, identity map, UoW | Full: lazy + eager, rich relationship set |
| Backends | MySQL/MariaDB, PostgreSQL, SQLite | MySQL, PostgreSQL, SQLite | Many (via DBAL) | MySQL, PostgreSQL, SQLite, SQL Server |
| Driver / session layer | Pluggable `DbSession`: PDO, **mysqli, wpdb** + retry decorator | **PDO only** | DBAL | PDO |
| Bulk writes | First-class: deadlock-safe multi-mask upsert, per-chunk-commit chunking, burn-free upsert | None (row-at-a-time `save()`) | Batch inserts; bulk `UPDATE`/`DELETE` via DQL | `upsert()` / bulk `insert()` |
| Concurrency | Tier-ordered `FOR UPDATE` locks, advisory locks, transient-retry decorator | None | Pessimistic + optimistic locking | `lockForUpdate()` / `sharedLock()` |
| Maturity / ecosystem | **Young, pre-1.0, small** | Mature (since 2010), established | Mature, large | Mature, very large |

<sub>attrecord and php-activerecord ship as a **single package with zero runtime dependencies**
(php-activerecord needs a PDO driver extension); their figures are shipped source — attrecord `src/`
≈ 300 KB at v0.3.0 (only ~130 KB is code; the rest is docblocks), php-activerecord `lib/` ≈ 280 KB.
Doctrine and Eloquent figures are a full `composer require --prefer-dist` install **including their
dependency trees** (`doctrine/orm`, `illuminate/database`); counts and sizes vary by version.</sub>

**Reach for php-activerecord** when you want the classic Rails-style experience over an *existing*
database — dynamic finders, magic attributes, a mature validations / callbacks / serialization suite,
15 years of battle-testing — you're on PDO, and you're happy to let the live schema be the source of
truth rather than authoring it in code.

**Reach for Doctrine** when you have a rich domain model and want data-mapper purity — identity map,
unit of work, lazy object graphs, DQL, a full migrations toolchain — and don't mind the weight.

**Reach for Eloquent** when you're in Laravel (or want its ergonomics standalone) and value the
convention-driven speed and enormous ecosystem.

**Reach for attrecord** when you want a small, dependency-free, framework-agnostic Active Record where
the PHP class *is* the schema (attributes → emitted DDL; declarative migrations are a planned opt-in
add-on), and you need strong
multi-backend, bulk-write, and concurrency ergonomics — accepting a young, pre-1.0 library with a
deliberately smaller surface and ecosystem.

---

## Background — old ideas, young package, lean by necessity

attrecord didn't start from a blank page. Most of its design — the attribute-declared schema, the
deadlock-safe bulk-write and locking patterns, the dialect-portable DDL — was distilled from a
production ERP I built and ran for over a decade (2012–2023). The *package* is young and pre-1.0;
the *ideas* have years of production mileage.

Its shape comes from where it runs today: a distributed WooCommerce plugin, where the data layer
ships *inside* the plugin zip and its dependencies can collide with whatever other plugins have
vendored on the same site. There, a twenty-package ORM isn't an option — zero runtime dependencies
and a small footprint are survival constraints, not preferences. So attrecord is dependency-free and
framework-agnostic (the WordPress `wpdb` adapter is optional; the core has no WP coupling), and that
constraint is permanent: its primary consumer is still a bundled plugin, so "one package, no
dependencies" is a design invariant — not a stage it will grow out of.

---

## Installation

```bash
composer require nandan108/attrecord
```

Requires PHP 8.1+. No runtime dependencies.

---

## Documentation

This README is the narrative guide. Deeper references live in [`docs/`](docs/):

- [llm-reference.md](docs/llm-reference.md) — exhaustive, AI-ingestion-oriented reference
  (every attribute, method signature, enum, dialect difference, and invariant in one place)
- [ddl-generation.md](docs/ddl-generation.md) — `CREATE TABLE` emission (MySQL, PostgreSQL, SQLite)
- [column-casting.md](docs/column-casting.md) — the `#[Cast]` family and `JsonCastable`
- [where-clause.md](docs/where-clause.md) — the `WhereClause` builder grammar
- [polymorphic-relations.md](docs/polymorphic-relations.md) — morph relations
- [arch-concurrency.md](docs/arch-concurrency.md) — production locking model, retryable-error classification, `RetryingDbSession`
- [arch-bulk-update-scaling.md](docs/arch-bulk-update-scaling.md) — the join-based bulk-`UPDATE` emitter and `saveAll()` chunking rationale
- [design-note-no-name-auto-conversion.md](docs/design-note-no-name-auto-conversion.md) — why no auto snake/camel conversion

---

## Quick start

### 1 — Define your records

```php
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Attribute\{Table, Column, Relation};
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\{ColumnType, RelationType};

enum OrderStatus: string
{
    case Draft = 'draft';
    case Placed = 'placed';
    case Shipped = 'shipped';
}

#[Table(name: 'orders')]
class Order extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Enum)]                // ENUM(...) value set derived from OrderStatus' cases
    #[EnumCaster(OrderStatus::class)]
    public OrderStatus $status = OrderStatus::Draft;

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

Optional: prefix every `Record` subclass table name globally (useful for WordPress or
multi-tenant single-DB setups). Call before any DB operation:

```php
Record::setTablePrefix('wp_');   // Order → `wp_orders`, OrderLine → `wp_order_lines`
```

The prefix is prepended to whatever appears in `#[Table(name: …)]`. Changing it clears
the schema cache so subsequent operations see the new prefix.

### 3 — Use

```php
// INSERT — classic style
$order = new Order();
$order->status = 'pending';
$order->total  = 99.95;
$order->save();               // INSERT INTO `orders` …
echo $order->id;              // auto-assigned PK

// INSERT — fluent factory style
$order = Order::newWith(['status' => 'pending', 'total' => 99.95])->save();
echo $order->id;              // auto-assigned PK

// Bulk-assign on an existing instance
$order->set(['status' => 'confirmed', 'total' => 149.00])->save();

// set() calls validate() by default — pass false to defer validation
// (useful for test fixtures or staged construction across multiple set() calls).
// save() / saveAll() will still validate at the boundary.
$order->set(['status' => 'confirmed'], validate: false);

// save() always returns $this — check $_saved if you need to know whether a write occurred
$order->save();
$order->_saved;   // true  = INSERT or UPDATE was issued
                  // false = record was clean, nothing sent to DB
                  // null  = save() not yet called on this instance

// SELECT by PK
$order = Order::getOne(42);          // ?Order
$order = Order::getOneOrFail(42);    // Order  (throws RecordNotFoundException if missing)
$order = Order::getOneOrNew(42);     // Order (new, unsaved instance if missing)

// UPDATE — only dirty columns
$order->status = 'confirmed';
$order->save();   // UPDATE `orders` SET `status` = ? WHERE `id` = ?

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

// findOne accepts ORDER BY and FOR UPDATE too
$latestPending = Order::findOne(
    '`status` = ?',
    ['pending'],
    orderByLimit: 'ORDER BY `placed_at` DESC',
    forUpdate:    true,    // inside transactional() only
);

// Count
$count = Order::countWhere('`status` = ?', ['pending']);

// Bulk update — column → value map; values are typed via the column's serializer
$updated = Order::updateWhere(
    ['status' => 'archived'],
    '`status` = ? AND `placed_at` < ?',
    ['draft', '2024-01-01'],
);

// Bulk delete
$deleted = Order::deleteWhere('`status` = ? AND `total` IS NULL', ['draft']);
```

### `RawSql` — raw SQL fragments with optional bound params

`RawSql` wraps an untranslated SQL expression with optional `?`-placeholder params.
The expression is embedded verbatim — **the caller is responsible for quoting
identifiers and never embedding user input** — but values can still be bound safely
through `?` placeholders.

```php
use Nandan108\Attrecord\RawSql;

// Increment a counter — no params needed
Order::updateWhere(
    ['view_count' => new RawSql('`view_count` + 1')],
    '`id` = ?', [$id],
);

// Conditional bulk write
Order::updateWhere(
    ['priority' => new RawSql('CASE WHEN `total` > 500 THEN 1 ELSE 0 END')],
    '`status` = ?', ['pending'],
);

// Parameterised raw expression — RawSql params come BEFORE the WHERE params
Order::updateWhere(
    ['priority' => new RawSql('GREATEST(?, `priority`)', [5])],
    '`status` = ?', ['pending'],
);
```

The same `RawSql` can be reused as a WHERE condition via
`WhereClause::whereRaw($raw)`, so a complex expression can be built once and applied
in either position:

```php
$jsonHas = new RawSql('JSON_CONTAINS(`tags`, ?)', ['"featured"']);

Order::find(WhereClause::whereRaw($jsonHas));
// ...
Order::updateWhere(
    ['featured_at' => new RawSql('NOW()')],
    WhereClause::whereRaw($jsonHas),
);
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
`whereInTuples`, `whereLike`, `whereBetween`, `whereNot`, `whereRaw`, variadic
combinators, and the `render($dialect)` API.

---

## Unique keys, indexes & targeted upserts

Declare non-PK unique keys with `#[UniqueKey('name')]` and non-unique secondary
indexes with `#[Index('name')]`. Both attributes can be applied at either property
or class level.

**Property level** (single-column keys, or composites with column ordering matching
property declaration order):

```php
use Nandan108\Attrecord\Attribute\{Column, UniqueKey, Index};

#[Table(name: 'inventory_items')]
class InventoryItem extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    // Single-column unique key
    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('sku')]
    public string $sku = '';

    // Compound unique key: (location_id, bin) — same name on both columns,
    // composite ordering follows property declaration order
    #[Column(ColumnType::BigIntUnsigned)]
    #[UniqueKey('loc_bin')]
    public int $location_id = 0;

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('loc_bin')]
    public string $bin = '';

    // Single-column secondary index
    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_qty')]
    public int $qty = 0;
}
```

**Class level** (composite keys with explicit column ordering, independent of
property declaration order):

```php
#[Table(name: 'inventory_items')]
#[UniqueKey('uk_loc_bin',   columns: ['location_id', 'bin'])]
#[Index    ('idx_loc_qty',  columns: ['location_id', 'qty'])]
class InventoryItem extends Record { /* ... */ }
```

Class-level form **requires** `columns: [...]`; property-level form **forbids** it.
A given key/index name must be declared via one form only.

### `upsertByUniqueKey($conflictKey, $updateColumns)`

INSERT this record; on conflict on the named unique key, UPDATE only the listed
columns. Dialect-aware (uses `ON DUPLICATE KEY UPDATE` on MySQL/MariaDB, `ON CONFLICT
… DO UPDATE` on PostgreSQL and SQLite).

```php
$item = new InventoryItem();
$item->sku = 'WIDGET-1';
$item->location_id = 1;
$item->bin = 'A-01';
$item->qty = 10;

// Insert if new; on SKU conflict, only overwrite qty
$item->upsertByUniqueKey('sku', updateColumns: ['qty']);
```

#### Burn-free mode — `preserveAutoIncrement: true`

`INSERT … ON DUPLICATE KEY UPDATE` allocates **and discards** an auto-increment value
on every conflicting write (MySQL/MariaDB with `innodb_autoinc_lock_mode = 1`), so an
idempotent re-write of an existing row silently inflates the counter — a problem for
small id domains re-registered on every request (registries, config rows).

Pass `preserveAutoIncrement: true` to get a SELECT-then-`UPDATE`/`INSERT` instead: the
row is looked up by the conflict key and updated in place when it exists (no allocation),
and inserted only when genuinely new. The cost is a second statement and a small
non-atomic window — fine for low-concurrency registry/config writes; prefer the atomic
default when burn is a non-issue.

```php
// Re-registering the same SKU never advances the auto-increment counter
$item->upsertByUniqueKey('sku', updateColumns: ['qty'], preserveAutoIncrement: true);
```

A conflict key that includes a **generated column** (e.g. a `STORED`
`IFNULL(scope_id, 0)`) works too — set the property to the value the DB will compute so
the lookup matches; the column is still skipped in the INSERT (the DB recomputes it).

### `updateByUniqueKey($fields = [])`

Direct UPDATE without loading the row first. The WHERE clause is built automatically
from the PK if non-null, else from the first declared `#[UniqueKey]` whose columns are
all non-null. With an empty `$fields`, all non-null non-PK non-autoIncrement columns
are written; pass an explicit list when you need to write nulls or restrict the SET
clause.

```php
$item = new InventoryItem();
$item->sku = 'WIDGET-1';   // matches the 'sku' unique key
$item->qty = 25;

// UPDATE inventory_items SET qty = 25 WHERE sku = 'WIDGET-1'
$affected = $item->updateByUniqueKey();

// Restrict / allow nulls explicitly
$item->updateByUniqueKey(fields: ['qty', 'notes']);
```

Returns the affected row count (0 if no match, 1 on success). Throws
`AttrecordException` when no viable WHERE clause can be built.

---

## Schema generation — `CREATE TABLE` from your attributes

Emit a fresh-install `CREATE TABLE` statement directly from the compiled
`TableSchema`. The same attribute metadata that drives CRUD also drives DDL —
no parallel hand-maintained DDL string.

```php
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Schema\TableSchema;

$sql = (new MysqlDialect())->buildCreateTable(
    TableSchema::fromClass(Order::class),
);
// CREATE TABLE `orders` (
//   `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
//   `customer_id` BIGINT UNSIGNED NOT NULL,
//   `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
//   `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
//                ON UPDATE CURRENT_TIMESTAMP,
//   PRIMARY KEY (`id`),
//   UNIQUE KEY `uk_external` (`external_ref`),
//   KEY `idx_status_date` (`status`, `created_at`),
//   CONSTRAINT `fk_orders_customer_id` FOREIGN KEY (`customer_id`)
//     REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

All three dialects implement it. `PgsqlDialect::buildCreateTable()` emits the PostgreSQL
equivalent from the same attributes — `BIGSERIAL` for an auto-increment PK, no `UNSIGNED`,
`BYTEA` for binary, `NUMERIC` for decimal, `BOOLEAN`, `JSONB`, an `Enum` column as `TEXT`
plus a `CHECK (... IN (...))` constraint, and FK constraints. Because PostgreSQL cannot
declare secondary indexes or comments inline, those are emitted as trailing `CREATE INDEX`
and `COMMENT ON` statements in the same (semicolon-separated) batch — safe to run in one
`PDO::exec()`. Engine/charset/collation table options and a column `ON UPDATE` clause are
MySQL-isms with no PostgreSQL column-clause equivalent and are not emitted; a `VIRTUAL`
generated column and the `Set` type are rejected with a `SchemaException`.

```php
use Nandan108\Attrecord\Dialect\PgsqlDialect;

$sql = (new PgsqlDialect())->buildCreateTable(TableSchema::fromClass(Order::class));
// CREATE TABLE "orders" (
//   "id" BIGSERIAL,
//   "customer_id" BIGINT NOT NULL,
//   "status" VARCHAR(20) NOT NULL DEFAULT 'pending',
//   ...
//   PRIMARY KEY ("id"),
//   CONSTRAINT "uk_external" UNIQUE ("external_ref"),
//   CONSTRAINT "fk_orders_customer_id" FOREIGN KEY ("customer_id")
//     REFERENCES "customers" ("id") ON DELETE CASCADE ON UPDATE RESTRICT
// );
// CREATE INDEX "idx_status_date" ON "orders" ("status", "created_at")
```

`SqliteDialect::buildCreateTable()` emits the SQLite equivalent from the same attributes. A
single auto-increment PK is rendered inline as `INTEGER PRIMARY KEY AUTOINCREMENT` (with no
separate `PRIMARY KEY` clause); column types collapse to SQLite's affinities (integer/bool →
`INTEGER`, `Float`/`Double` → `REAL`, `Decimal` → `NUMERIC`, binary → `BLOB`, everything else
— text family, `Json`, `Enum`, and the date/time types stored as ISO-8601 text — → `TEXT`); an
`Enum` column is `TEXT` plus a `CHECK (... IN (...))` constraint; generated columns
(`STORED`/`VIRTUAL`) and FK constraints are supported; secondary indexes are emitted as
trailing `CREATE INDEX` statements in the same batch. Column comments and a MySQL `ON UPDATE`
clause have no SQLite equivalent and are dropped; the `Set` type is rejected with a
`SchemaException`. FK constraints are only *enforced* at runtime when `PRAGMA foreign_keys=ON`,
which the dialect applies automatically on connection open (see below).

```php
use Nandan108\Attrecord\Dialect\SqliteDialect;

$sql = (new SqliteDialect())->buildCreateTable(TableSchema::fromClass(Order::class));
// CREATE TABLE "orders" (
//   "id" INTEGER PRIMARY KEY AUTOINCREMENT,
//   "customer_id" INTEGER NOT NULL,
//   "status" TEXT NOT NULL DEFAULT 'pending',
//   ...
//   CONSTRAINT "uk_external" UNIQUE ("external_ref"),
//   CONSTRAINT "fk_orders_customer_id" FOREIGN KEY ("customer_id")
//     REFERENCES "customers" ("id") ON DELETE CASCADE ON UPDATE RESTRICT
// );
// CREATE INDEX "idx_status_date" ON "orders" ("status", "created_at")
```

### Attribute fields used in DDL emission

`#[Column]` additions beyond type/length/nullable:

```php
#[Column(
    type:        ColumnType::DateTime,
    default:     null,                    // literal default (int|float|string|bool|null)
    defaultExpr: 'CURRENT_TIMESTAMP',     // raw SQL default expression (mutually exclusive with default)
    onUpdate:    'CURRENT_TIMESTAMP',     // raw SQL ON UPDATE clause
    comment:     'When the order was placed',
    enumValues:  null,                    // list<string> — required for ColumnType::Enum and Set
)]
```

`#[Table]` carries only cross-dialect fields (`name`, `primaryKey`, `comment`).
MySQL-specific options live on a separate `#[MysqlTableOptions]` class-level
attribute that other dialects ignore. Every field is nullable so you override
only what you care about; `MysqlDialect` supplies sensible defaults
(`InnoDB` / `utf8mb4` / `utf8mb4_unicode_ci`) for fields left null and for
Records that omit `#[MysqlTableOptions]` entirely.

```php
use Nandan108\Attrecord\Attribute\{Table, MysqlTableOptions};

#[Table(name: 'orders', primaryKey: 'id', comment: 'Customer orders')]
#[MysqlTableOptions(engine: 'Memory')]   // override engine only; charset/collation stay default
final class Order extends Record { /* ... */ }
```

A future `#[PgsqlTableOptions(...)]` will carry Postgres-specific options
(tablespace, UNLOGGED, etc.) following the same pattern.

`#[Relation]` FK-constraint controls:

```php
#[Relation(
    type:       RelationType::ManyToOne,
    class:      Customer::class,
    foreignKey: 'customer_id',
    onDelete:   ForeignKeyAction::Cascade,    // default: Restrict
    onUpdate:   ForeignKeyAction::Restrict,   // default: Restrict
    emitFk:     true,                          // opt-out per-relation
)]
```

FK constraints are emitted only for owning-side relations (`ManyToOne`,
`OneToOne`). Polymorphic and inverse-side relations carry no local FK column
and are always skipped.

#### Constraint-only foreign keys — `#[ForeignKey]`

`#[Relation]` emits an FK *and* gives you object hydration. When you want the FK
constraint **only** — or the target has no Record at all — use the class-level,
repeatable `#[ForeignKey]` attribute. The local column is a plain `#[Column]` on this
Record; the attribute names the target, which may be either a **Record class-string**
(table + PK derived from it, rename-safe) or a **table name** string (for a hand-written
or externally owned table attrecord doesn't model):

```php
use Nandan108\Attrecord\Attribute\{Table, Column, ForeignKey};
use Nandan108\Attrecord\Enum\ForeignKeyAction;

#[Table(name: 'inventory_ledger')]
#[ForeignKey(column: 'subject_id', references: Subject::class)]                       // → `subjects`(`id`), derived
#[ForeignKey(column: 'from_slot_id', references: 'slotspace', referencesColumn: 'id', // → raw table, no Record
    onDelete: ForeignKeyAction::SetNull)]
final class InventoryLedger extends Record
{
    #[Column(ColumnType::BigIntUnsigned)]
    public int $subject_id = 0;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $from_slot_id = null;
    // ...
}
```

Parameters: `column` (local FK column), `references` (target Record class **or** table
name), `referencesColumn` (target column, default `id`), `onDelete` / `onUpdate`
(default `Restrict`). The active table prefix is applied to a literal table name; the
target is resolved lazily at DDL-build time. A `references` value that is a class but
**not** a `Record` subclass throws.

Schema-build time validation surfaces mistakes early: `VarChar`/`Char`/`Decimal`/
`Enum`/`Set` required arguments, mutually exclusive `default` / `defaultExpr`,
class- vs property-level key form conflicts, FK column references.

### Generated columns

A column whose value is computed by the database (`GENERATED ALWAYS AS (...)`)
is declared by adding `generatedAs:` to the `#[Column]` attribute. The PHP
property becomes effectively read-only: attrecord excludes generated columns
from every INSERT and UPDATE it emits — assigning a value in PHP simply has
no effect on the row.

```php
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;

#[Column(
    type:           ColumnType::IntUnsigned,
    generatedAs:    'IFNULL(scope_actor_id, 0)',
    generatedMode:  GeneratedColumnMode::Stored,   // or Virtual; defaults to Stored
)]
public int $scope_actor_key = 0;
```

Emitted DDL (the `scope_actor_key` column participates in compound keys,
indexes, and FK targets just like a regular column):

```sql
`scope_actor_key` INT UNSIGNED GENERATED ALWAYS AS (IFNULL(scope_actor_id, 0)) STORED,
```

`STORED` columns are materialized on disk (indexable without restriction);
`VIRTUAL` columns are recomputed on each read (no storage cost, indexable in
MySQL 8+ with caveats).

Schema-build validation enforces the mutual exclusions MySQL/MariaDB also
enforce at DDL time:

- `default` / `defaultExpr` not allowed on a generated column
- `onUpdate` not allowed
- `autoIncrement` not allowed
- `generatedAs` must be a non-empty SQL expression
- `generatedMode` without `generatedAs` is rejected

`NULL` / `NOT NULL` is intentionally not emitted on generated columns —
MySQL accepts it but MariaDB rejects it, and the generated expression already
determines nullability. Use this for portable schemas across both engines.

### Out of scope

`ALTER TABLE` generation, schema diffing, and migration tracking are
**deliberately out of scope** of attrecord itself. They belong in a separate
package built on top of `TableSchema`.

→ See [docs/ddl-generation.md](docs/ddl-generation.md) for the full reference
(type rendering table, column-line format, validation rules, testing strategy).

### `updateByWhere($where, $params = [], $fields = [])`

Bulk UPDATE driven by instance properties — same SET-clause semantics as
`updateByUniqueKey()` but with a caller-supplied WHERE clause. Useful when you want
type-safe value assignment via record properties but the WHERE is not derivable from
a PK or declared unique key.

```php
$proto = new InventoryItem();
$proto->qty = 0;

// Zero out qty for every item at a given location
$proto->updateByWhere('`location_id` = ?', [$locationId]);
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

## Column casting

By default each column maps to a native PHP type from its `ColumnType`. **Casting** lets
a property hold a richer value — a value object, a typed array, a decoded JSON payload —
serialized transparently on write and reconstructed on read. It's opt-in: native types
keep their built-in mapping, and a cast only kicks in where you ask for one (or where the
native default would be wrong, e.g. an `array`-typed `Json` column).

```php
// array ⇄ JSON — auto-attached on a Json column typed as array
#[Column(ColumnType::Json, nullable: true)]
public ?array $meta = null;

// value object ⇄ JSON — auto-attached when the type implements JsonCastable
#[Column(ColumnType::Json, nullable: true)]
public ?Money $price = null;

// explicit, parameterized caster
#[Column(ColumnType::Json, nullable: true)]
#[JsonCaster(excludeNullFields: ['note'])]
public ?array $audit = null;

// reshape a native type — e.g. store a timestamp as a unix int
#[Column(ColumnType::BigIntUnsigned, nullable: true)]
#[EpochCaster]
public ?\DateTimeImmutable $logged_at = null;
```

A caster *is* its attribute: `JsonCaster` / `DateTimeCaster` / `EpochCaster` / `EnumCaster` ship
built-in, and custom casters extend the `Cast` base (which implements the two-method
`ColumnCaster` contract). `#[EnumCaster(MyEnum::class)]` maps a scalar column to/from a backed enum
(see the `$status` field in [Define your records](#1--define-your-records)); on a `ColumnType::Enum`
column it also derives the `ENUM(...)` value set from the enum's cases. Casting integrates with dirty
tracking — including mutable value objects — and with bulk `saveAll()`, and has no effect on
generated DDL — except the `EnumCaster`-derived `ENUM(...)` set just noted.

→ See [docs/column-casting.md](docs/column-casting.md) for the full reference: the
`ColumnCaster` contract, `JsonCastable` value objects, discriminated payloads, auto-attach
rules, and limitations.

---

## Validation

Subclasses override `validate()` to enforce domain invariants — field-level rules
(positive ids, non-empty required strings) and cross-field constraints (mutually
exclusive flags, dates ordered, etc.). The base implementation is a no-op, so records
without invariants need not override.

```php
use Nandan108\Attrecord\Exception\RecordValidationException;

class Order extends Record
{
    // ... columns ...

    public function validate(): void
    {
        if ($this->total !== null && $this->total < 0) {
            throw new RecordValidationException(
                'Order total cannot be negative.',
                context: ['total' => $this->total],
            );
        }
        if ($this->status === 'shipped' && $this->placed_at === null) {
            throw new RecordValidationException('Shipped order must have a placed_at.');
        }
    }
}
```

`validate()` runs automatically at three points:

- At the end of `set()` when `$validate` is true (the default) — catches invalid state
  at the point of mass assignment.
- Inside `save()` just after `beforeSave()` — guarantees no invalid row reaches the DB,
  even if a caller bypassed `set()` and assigned properties directly.
- Inside `RecordSet::saveAll()` in the same loop as `beforeSave()`.

Throw `RecordValidationException` (or a subclass) with a human-readable message and
optional `context` array. The context is stored on the exception for the caller's
error handling.

---

## RecordSet

`find()` returns a `RecordSet<T>` — a typed, iterable collection.

```php
$orders = Order::find('`status` = ?', ['pending']);

count($orders);             // Countable
foreach ($orders as $o) {} // Iterator

$orders->first();           // ?Order
$orders->last();            // ?Order

// Extract one field, keyed by the PK
$idToTotal = $orders->pluck('total');           // [pk => total]

// Extract multiple fields, keyed by the PK
$details = $orders->pluck(['status', 'total']); // [pk => ['status' => …, 'total' => …]]

// Group + extract — extra args are the grouping key(s); leaves are field values
$byStatusTotals = $orders->pluck('total', 'status');
// ['pending' => [10.0, 25.5, …], 'confirmed' => [99.95, …]]

$byStatusDetails = $orders->pluck(['id', 'total'], 'status');
// ['pending' => [['id' => 1, 'total' => 10.0], …], …]

// Index by a unique column
$byId = $orders->recordsByKey('id');  // array<int|string, Order>

// Group by a single column
$byStatus = $orders->recordsGroupedByKey('status');  // array<string, RecordSet<Order>>

// Nested group by multiple columns — leaves are RecordSets, not plain arrays
$byStatusByYear = $orders->recordsGroupedByKeys('status', 'year');
// ['pending' => [2024 => RecordSet<Order>, 2025 => RecordSet<Order>], …]

// Convert to raw arrays (column → scalar) — useful for serialisation
$rows = $orders->toArraySet();   // list<array<string, scalar|null>>
```

### Bulk operations

```php
// Stamp a shared field on every record before saving (e.g. updated_at, actor_id)
$set = new RecordSet([$line1, $line2, $line3]);
$set->bulkSet(['updated_by' => $userId]);

// Batch upsert — deadlock-safe 3-step strategy for all dirty records, one atomic transaction
$result = $set->saveAll();   // ?SaveResult — null when nothing to save

$result->inserted;      // rows newly written
$result->updated;       // rows overwritten
$result->total();       // inserted + updated
$result->insertedIds;   // list<int|string> — PKs of newly inserted auto-increment records

// Force-save (skip dirty filter) — useful in tests and for re-asserting state
$set->saveAll(force: true);

// Bulk delete
$deleted = $set->deleteAll();  // DELETE FROM … WHERE id IN (…)
```

**Notes on `saveAll()`:**

- Clean records are skipped automatically (pass `force: true` to override).
- `beforeSave()` and `validate()` run on every dirty record before any SQL is issued.
- `insertedIds` is populated for new (no-PK) records: via `RETURNING` on PostgreSQL and SQLite,
  or via `lastInsertId()` + sequential range on MySQL/MariaDB. On MySQL/MariaDB clustered setups
  with non-sequential auto-increment, use individual `Record::save()` calls instead.
- For tables with natural (non-auto-increment) PKs, `saveAll()` performs a true upsert (PKs are
  already set on the PHP objects).
- The keyed (known-PK) upsert is a single set-based statement — `INSERT IGNORE` → an ordered
  `SELECT … FOR UPDATE` (ascending PK, for a consistent lock order) → one derived-table join that
  carries a per-row bitmask so each row updates only the columns it actually changed. The join is
  `O(N·M)` in the SQL text (N rows × M updatable columns) rather than the `O(N²)` a per-row `CASE`
  would produce. See [docs/arch-bulk-update-scaling.md](docs/arch-bulk-update-scaling.md) for the
  full rationale.

`Record::save()` accepts the same `$force` flag — `$order->save(force: true)` writes every
column regardless of dirty state.

#### Chunked writes — `saveAll(chunkSize:)`

By default `saveAll()` runs the whole set in **one transaction** — all-or-nothing. For very large
batches, pass an integer `chunkSize` to split the write into that-many-row slices that
**commit per chunk**, bounding the lock and undo/redo footprint each transaction holds:

```php
// 50k rows written 1000 at a time; each 1000-row chunk commits before the next begins
$set->saveAll(chunkSize: 1000);
```

This trades whole-set atomicity for a bounded footprint: a mid-run failure leaves earlier
chunks **committed**, so the operation must be **resumable**. It is — dirty-tracking makes it so.
After each chunk commits, its records are marked clean, so simply re-calling `saveAll()` on the
same set skips everything already written and retries only the rest. Keyed (known-PK) records are
sorted by PK ascending before chunking, so each chunk locks a contiguous ascending range and
chunks proceed low→high — preserving the global ascending-PK lock-order invariant.

Per-chunk commit is impossible **inside an already-open transaction** (the outer transaction holds
every lock until it commits), so a chunked `saveAll()` nested in a transaction **throws**
`AttrecordException` by default. Pass `allowInTransactionChunking: true` to acknowledge this and
chunk anyway: the chunks then run as separate, smaller **statements** within the outer transaction
— bounding statement size while staying atomic, but leaving the lock/undo footprint unbounded.

### `upsertAllByUniqueKey($conflictKey)` — bulk burn-free upsert

The loop-free, auto-increment-burn-free counterpart of an
`INSERT … ON DUPLICATE KEY UPDATE` batch — the `RecordSet` analogue of
`Record::upsertByUniqueKey(..., preserveAutoIncrement: true)`.

```php
// $rows are PK-less Records whose conflict-key column(s) are set
$result = (new RecordSet($rows))->upsertAllByUniqueKey('uniq_owner_code');
```

One `SELECT … WHERE (conflict cols) IN (…)` resolves the PKs of rows that already exist
and assigns them onto the matching records; `saveAll()` then routes those through its
keyed upsert (PK supplied → no allocation) while genuinely-new records take its plain
bulk `INSERT` (one id each, none wasted). Returns the same `?SaveResult` as `saveAll()`
(`null` for an empty set). Records that already carry a PK are left untouched. Same
non-atomic caveat as the single-record burn-free path. Throws `AttrecordException` if
`$conflictKey` isn't a declared `#[UniqueKey]`.

---

## Relation loading

Load relations onto an already-fetched set — imperatively, one extra query per relation level, no
N+1 and no JOINs:

```php
// One extra query per level
$orders = Order::find('`status` = ?', ['pending'])
    ->load('lines');          // SELECT … WHERE order_id IN (…)

foreach ($orders as $order) {
    foreach ($order->lines as $line) {
        echo $line->sku;
    }
}

// Dot-notation chains
$orders->load('lines.product');  // loads lines, then products for those lines

// Skip records that already have the relation loaded
$orders->loadMissing('lines.product');

// The same API is on a single record
$order->load('lines', 'customer.billing');
```

`load()` always (re)fetches; `loadMissing()` loads only where the relation isn't already present —
and a to-one that resolved to `null` still counts as loaded, so it isn't re-queried. `with()`
remains as a deprecated alias for `load()` (removed at 1.0).

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
$orders = Order::find('`status` = ?', ['pending'])->load('tags');

foreach ($orders as $order) {
    foreach ($order->tags as $tag) {
        echo $tag->name;
    }
}

// Load tags with their polymorphic parent — one query per distinct type present
$tags = Tag::find()->load('tagable');

foreach ($tags as $tag) {
    // $tag->tagable is an Order or Product depending on tagable_type
}

// Chains work too: orders → tags → tagable (round-trip)
$orders->load('tags.tagable');
```

`load('tagable')` issues one `IN(…)` query per distinct type value present in the result
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

### `LockSet::acquire()` — multi-class lock acquisition

For compound operations that lock rows across several entity classes at once, use
`LockSet::acquire()`. It sorts the targets by their declared `#[LockTier]` (lowest
first), then issues `SELECT … FOR UPDATE` with `ORDER BY pk ASC` within each table.
This eliminates the class of deadlock caused by inconsistent acquisition order across
concurrent transactions.

```php
use Nandan108\Attrecord\LockSet;

PurchaseOrder::transactional(function (Transaction $tx) use ($poId, $lineIds, $slotId): void {
    $session = PurchaseOrder::connection()->session;

    $locks = LockSet::acquire($session, [
        PurchaseOrder::class     => [$poId],
        PurchaseOrderLine::class => $lineIds,
        InventorySlot::class     => [$slotId],
    ], $tx);

    // $locks[PurchaseOrder::class]     is RecordSet<PurchaseOrder>
    // $locks[PurchaseOrderLine::class] is RecordSet<PurchaseOrderLine>
    foreach ($locks[PurchaseOrderLine::class] as $line) {
        // … process under lock
    }
});
```

Throws `MissingLockTierException` if any target class lacks `#[LockTier]`, and
`LockTierConflictException` if two classes share the same tier in the same set.

### Advisory locks

`DbSession::withAdvisoryLock()` provides named application-level mutexes — backed by
`GET_LOCK` / `RELEASE_LOCK` on MySQL/MariaDB, and by `pg_advisory_lock` (keyed on a crc32
hash of the lock name) on a PostgreSQL PDO connection, where the wait timeout is emulated by
polling `pg_try_advisory_lock`. Advisory locks are connection-scoped and do not interact
with row or table locks — safe to nest inside a transaction.

```php
$conn = Record::connection();

$conn->session->withAdvisoryLock(
    lockName:       'invflux.reconcile.shipment-42',
    timeoutSeconds: 5,          // 0 = fail immediately, -1 = wait indefinitely
    callback:       function () {
        // ... serialise this critical section across all PHP workers
    },
);
```

---

## DB session adapters

All three implement `DbSession` and can be swapped without changing application code. Which
database you talk to is determined by the **session** (the connection) and the **dialect** you
pair it with — not by three different "MySQL adapters":

- **`PdoDbSession` is the cross-database adapter.** PDO is driver-agnostic, so this is how you
  connect to **PostgreSQL** and **SQLite** as well as MySQL/MariaDB — pair it with the matching
  `SqlDialect`. It even adapts internally per driver (e.g. `pg_advisory_lock` vs `GET_LOCK`,
  `bytea` binding, per-driver retryable-error classification). The PostgreSQL and SQLite test
  suites both run through it.
- **`MysqliDbSession` and `WpDbSession` are MySQL/MariaDB only** — the `mysqli` and WordPress
  `wpdb` extensions speak only MySQL, so there is no PostgreSQL equivalent to add (that's what
  `PdoDbSession` is for).

> End-to-end support is bounded by the shipped **dialects** — MySQL/MariaDB, PostgreSQL, and
> SQLite. `PdoDbSession` can open a connection to any PDO driver (SQL Server, Oracle, …), but
> using one as a full attrecord backend requires a matching `SqlDialect` implementation, which is
> not included for anything beyond those three.

Any custom `DbSession` must implement two error-classification methods the library relies on:
`isDuplicateKeyError(\Throwable)` (used by the burn-free upsert paths) and
`isRetryableTransactionError(\Throwable)` (used by `RetryingDbSession` to decide what to retry —
see below). The three shipped adapters implement both, classified per driver.

### PDO — recommended (MySQL, MariaDB, PostgreSQL, SQLite)

```php
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\Attrecord\Dialect\{MysqlDialect, PgsqlDialect, SqliteDialect};

// MySQL / MariaDB
$pdo  = new PDO('mysql:host=127.0.0.1;dbname=shop', 'user', 'pass');
$conn = new Connection(new PdoDbSession($pdo), new MysqlDialect());

// PostgreSQL — same adapter, paired with PgsqlDialect
$pdo  = new PDO('pgsql:host=127.0.0.1;dbname=shop', 'user', 'pass');
$conn = new Connection(new PdoDbSession($pdo), new PgsqlDialect());

// SQLite — same adapter, paired with SqliteDialect (file-based or :memory:)
$pdo  = new PDO('sqlite:/var/data/shop.sqlite');
$conn = new Connection(new PdoDbSession($pdo), new SqliteDialect());
```

#### SQLite backend & connection hardening

The SQLite dialect requires **SQLite >= 3.33** (2020-08), because its bulk upsert uses the
`UPDATE … FROM` join form introduced in that release. Reading generated PKs back uses
`RETURNING` (SQLite 3.35+), so a multi-row `saveAll()` returns every inserted id rather than only
the last rowid.

`Connection`'s constructor runs each of the dialect's `connectionInitStatements()` on the fresh
session immediately, so a raw SQLite handle is brought to a sane baseline the moment you wrap it.
`SqliteDialect` uses this to emit — configurable via its constructor:

```php
new SqliteDialect(
    journalMode:   'WAL',   // PRAGMA journal_mode (default 'WAL'; pass null to leave the default)
    busyTimeoutMs: 5000,    // PRAGMA busy_timeout in ms (default 5000; null to skip)
    foreignKeys:   true,    // PRAGMA foreign_keys=ON (default true — SQLite defaults it OFF)
);
```

WAL improves read/write concurrency, `busy_timeout` lets a writer wait out a competing writer
rather than failing instantly with `SQLITE_BUSY`, and `foreign_keys=ON` makes the FK constraints
emitted in DDL actually enforced. `MysqlDialect` and `PgsqlDialect` return an empty
`connectionInitStatements()` array, so the hook is a no-op for them.

### mysqli (MySQL/MariaDB)

```php
use Nandan108\Attrecord\Session\MysqliDbSession;

$mysqli = new mysqli('127.0.0.1', 'user', 'pass', 'shop');
$conn   = new Connection(new MysqliDbSession($mysqli), new MysqlDialect());
```

### WordPress wpdb (MySQL/MariaDB)

```php
use Nandan108\Attrecord\Session\WpDbSession;

global $wpdb;
$conn = new Connection(new WpDbSession($wpdb), new MysqlDialect());
Record::setConnection($conn);
```

`WpDbSession` converts attrecord's `?` placeholders to `%s` for `wpdb::prepare()` and escapes existing `%` in LIKE clauses automatically.

### `RetryingDbSession` — automatic retry of transient conflicts

Under real concurrency, an otherwise-correct transaction can still fail transiently — a deadlock,
a lock-wait timeout, a serialization failure, or `SQLITE_BUSY`. The usual remedy is to simply
re-run it. `RetryingDbSession` is a `DbSession` **decorator** that does exactly that: it wraps any
session and retries the **outer** transaction on a transient conflict, with exponential backoff
plus jitter. It's opt-in and composable — wrap a session with it only where you want retries:

```php
use Nandan108\Attrecord\Session\{RetryingDbSession, PdoDbSession};
use Nandan108\Attrecord\Dialect\PgsqlDialect;

$conn = new Connection(new RetryingDbSession(new PdoDbSession($pdo)), new PgsqlDialect());
```

Every method except `transactional()` delegates verbatim to the wrapped session, so
`Record::transactional()` and `RecordSet::saveAll()` (which funnel through it) gain retries for
free. Only the **outermost** transaction is retried; a nested `transactional()` call runs inline
in the outer one.

```php
public function __construct(
    DbSession $inner,             // the session to wrap
    int $maxAttempts = 10,        // total attempts, including the first
    int $baseDelayUs = 5_000,     // base backoff in µs, doubled each attempt
    int $maxDelayUs  = 100_000,   // per-attempt backoff cap in µs
    ?\Closure $retryable = null,  // (\Throwable): bool — overrides the default classification
)
```

Which errors count as retryable comes from `$retryable` if you pass one, otherwise from the
wrapped session's `isRetryableTransactionError()` (classified per driver; the default **includes
deadlocks**, which most applications want retried). A consumer with strict lock-order discipline
that would rather surface a deadlock than retry it can pass an override predicate.

> **Idempotency contract.** The closure passed to `transactional()` is **re-run on each attempt**.
> Any effect inside it that the database does *not* roll back — an HTTP call, a queue publish, a
> file write, in-memory mutation — will repeat. Closures run under a `RetryingDbSession` must be
> safe to re-run (pure-SQL, or side-effect-free outside the DB).

→ See [docs/arch-concurrency.md](docs/arch-concurrency.md) for the full locking-and-retry model.

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

## Property name vs column name

PHP convention is `camelCase`; SQL convention is `snake_case`. Each `#[Column]`
property may declare an explicit column name; when omitted, the column name
equals the PHP property name:

```php
#[Table(name: 'orders', primaryKey: 'order_id')]
final class Order extends Record
{
    #[Column(ColumnType::BigIntUnsigned, name: 'order_id', autoIncrement: true)]
    public ?int $orderId = null;

    #[Column(ColumnType::BigIntUnsigned, name: 'customer_id')]
    public int $customerId = 0;

    // No `name:` override — column name equals property name
    #[Column(ColumnType::VarChar, length: 20)]
    public string $status = 'pending';
}
```

`#[Table(primaryKey: …)]` references the **column** name (not the property name).

Internally, `TableSchema` exposes both sides:

- `$schema->pk` — primary-key column name (used in SQL).
- `$schema->pkProp` — primary-key property name (used for PHP property access).
- `$schema->columns[$colName]->name` / `->propertyName` — same pairing per column.
- `$schema->propFor(string $colName): string` — helper that resolves column → property.

**No auto-conversion** is provided (not by default, not as opt-in). The rationale
is documented in [docs/design-note-no-name-auto-conversion.md](docs/design-note-no-name-auto-conversion.md) —
short version: it would turn IDE Rename Symbol into a silent schema migration,
hide column names from `grep`, and introduce an algorithmic derivation rule
that has to be remembered everywhere.

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
    name:          'col_name',  // SQL column name override (defaults to PHP property name)
    nullable:      true,        // allows NULL; PHP property becomes ?string
    autoIncrement: true,        // skipped in INSERT/UPDATE; PK assigned after INSERT
    trimOnSave:    true,        // trim whitespace on save; also suppresses dirty-detection for whitespace-only changes
    length:        255,         // for VarChar/Char/Binary/VarBinary; also enforced at DDL generation time
    precision:     10,          // Decimal: total digits (required, paired with scale); DateTime/Timestamp: fractional-seconds 0-6 (optional)
    scale:         2,           // Decimal scale (required); forbidden on other types
    default:       null,        // literal DEFAULT value (int|float|string|bool|null); see DDL section
    defaultExpr:   null,        // raw SQL DEFAULT expression, e.g. 'CURRENT_TIMESTAMP'
    onUpdate:      null,        // raw SQL ON UPDATE expression, e.g. 'CURRENT_TIMESTAMP'
    comment:       null,        // column comment (DDL-only)
    enumValues:    null,        // list<string> — required for ColumnType::Enum and Set
)]
```

### Binary columns

`Binary` / `VarBinary` columns hold raw bytes (e.g. an application-minted `BINARY(16)` /
`BYTEA` UUID primary key). Reads and writes through the normal `save()` / `getOne()` /
`find()` / `saveAll()` paths handle binary transparently on MySQL, PostgreSQL, and SQLite.

The handling is **dialect-gated**, so it's invisible to MySQL consumers: on MySQL/MariaDB,
binary values bind as ordinary byte strings exactly as any other string (so a custom
`DbSession` that only accepts scalars keeps working). Only when the active dialect reports
`bindsBinaryAsLob() === true` (PostgreSQL and SQLite) does `toParam()` wrap binary values in a
`BinaryParam` so the session can bind them as a LOB (`bytea` on PostgreSQL, `BLOB` on SQLite);
reads decode the wire stream back to raw bytes. Net effect: a non-UTF-8 byte string round-trips
correctly on all three engines, and nothing changes for a MySQL-only deployment.

The one case that needs help is an **ad-hoc `WhereClause` predicate on a binary column**,
where attrecord has no column metadata to drive the binding. On PostgreSQL and SQLite, wrap the
value in `Nandan108\Attrecord\BinaryParam` so the session binds it as binary rather than text (on
MySQL a plain byte string works, but wrapping is harmless):

```php
use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\WhereClause;

Subject::find(WhereClause::where('uuid', new BinaryParam($rawBytes)));
```

Binary lookups by **primary key** (`getOne($rawBytes)`, `delete()`) need no wrapping — the PK
column type is known and the wrapping is applied for you.

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
| `ManyToMany`       | Pivot (junction) table                   | `?RecordSet<T>`   | `class`, `pivotTable`, `pivotLocalKey`, `pivotForeignKey` |
| `HasManyThrough`   | Via an intermediate Record               | `?RecordSet<T>`   | `class`, `through`, `foreignKey`, `secondKey`  |

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

// Many-to-many through a pivot table (junction of two FK columns)
#[Relation(
    type:            RelationType::ManyToMany,
    class:           Tag::class,
    pivotTable:      'post_tag',     // junction table
    pivotLocalKey:   'post_id',      // pivot column → this record's PK
    pivotForeignKey: 'tag_id',       // pivot column → the target's PK
)]

// Has-many-through an intermediate Record (reach the far records, skip the middle)
#[Relation(
    type:       RelationType::HasManyThrough,
    class:      Comment::class,      // far records
    through:    Post::class,         // intermediate Record
    foreignKey: 'user_id',           // intermediate column → this record's PK
    secondKey:  'post_id',           // far column → the intermediate's PK
)]
```

`ManyToMany` is deliberately **pivot-less** — it returns the related records, not pivot-column data.
When the junction carries data, model it as its own Record and traverse a `OneToMany → ManyToOne`
chain (`$post->load('postTags.tag')`) for fully-typed pivot columns.

---

## Running tests

```bash
# Unit tests (no DB needed)
composer test -- --testsuite unit

# Integration tests (MariaDB + PostgreSQL via Docker; SQLite needs no server)
docker compose up -d
composer test -- --testsuite integration

# All tests
composer test

# One backend only (the integration suites are tagged by @group)
composer test -- --testsuite integration --group mysql
composer test -- --testsuite integration --group pgsql
composer test -- --testsuite integration --group sqlite
```

Each integration suite is a shared body of test cases (a `…Cases` trait under
`tests/Integration/Cases/`) bound to thin concrete classes — one per backend — so the
**same assertions run against MySQL, PostgreSQL, and SQLite**. Each suite's schema is generated
from its fixtures' attributes via `buildCreateTable()`, so the DDL producer is exercised on all
three engines on every run. SQLite runs in-process (no server); PostgreSQL tests skip (rather than
fail) when the container is absent.

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

### Code style & static analysis

Code style is enforced with [PHP CS Fixer](https://cs.fixer.dev/) (the `@Symfony` ruleset plus
project overrides in `.php-cs-fixer.php`), and types with [Psalm](https://psalm.dev/) at
level 1:

```bash
composer cs-fix     # apply PHP CS Fixer
composer cs-check   # report style violations without changing files (used in CI)
composer psalm      # static analysis — must be zero errors
```

All three (tests, Psalm, PHP CS Fixer) run in CI against PHP 8.1–8.4 with MySQL, PostgreSQL, and SQLite.

---

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for the dev setup
and the checks to run. Deferred ideas are tracked in [docs/backlog.md](docs/backlog.md).

---

## License

[MIT](LICENSE) © Samuel de Rougemont
