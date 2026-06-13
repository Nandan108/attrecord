# DDL generation — `CREATE TABLE` from `#[Table]` / `#[Column]` / `#[Relation]`

attrecord can emit `CREATE TABLE` statements directly from the same attribute
metadata it uses for CRUD. The goal is a **single source of truth** for schema:
column type, length, nullability, defaults, unique keys, indexes, and foreign-key
constraints all live on the Record class — no parallel hand-maintained DDL string.

This document covers **fresh-install DDL only**. Schema diffing, `ALTER TABLE`
generation, and migration tracking are deliberately out of scope.

---

## Public API

```php
$dialect = new MysqlDialect();
$schema  = TableSchema::fromClass(OrderRecord::class);
$sql     = $dialect->buildCreateTable($schema);

// Produces (illustrative):
// CREATE TABLE `wp_orders` (
//   `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
//   `customer_id` BIGINT UNSIGNED NOT NULL,
//   `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
//   `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
//                ON UPDATE CURRENT_TIMESTAMP,
//   PRIMARY KEY (`id`),
//   UNIQUE KEY `uk_orders_external` (`external_ref`),
//   KEY `idx_orders_status` (`status`, `created_at`),
//   CONSTRAINT `fk_orders_customer_id`
//     FOREIGN KEY (`customer_id`) REFERENCES `wp_customers` (`id`)
//     ON DELETE RESTRICT ON UPDATE RESTRICT
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The method lives on `SqlDialect`. `MysqlDialect` implements it; `PgsqlDialect`
throws `\RuntimeException` for now (Phase 2).

---

## Attribute surface

### `#[Column]` — new fields

| Field          | Purpose                                                          |
| -------------- | ---------------------------------------------------------------- |
| `name`         | SQL column name override; defaults to the PHP property name.     |
| `default`      | Literal default (int, float, string, bool, null). Quoted by dialect. |
| `defaultExpr`  | Raw SQL default expression (e.g. `'CURRENT_TIMESTAMP'`). Not quoted. |
| `onUpdate`     | Raw SQL `ON UPDATE` expression (e.g. `'CURRENT_TIMESTAMP'`).     |
| `comment`      | Column comment.                                                  |
| `enumValues`   | `list<string>` — required for `ColumnType::Enum` and `Set`.      |

`default` and `defaultExpr` are mutually exclusive; setting both throws
`SchemaException` at schema-build time.

#### Property name vs column name

PHP convention is `camelCase`, SQL convention is `snake_case`. Each `#[Column]`
property may declare an explicit column name; when omitted, the column name
equals the PHP property name:

```php
#[Table(name: 'orders', primaryKey: 'order_id')]
final class OrderRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, name: 'order_id', autoIncrement: true)]
    public ?int $orderId = null;

    #[Column(ColumnType::BigIntUnsigned, name: 'customer_id')]
    public int $customerId = 0;
}
```

`#[Table(primaryKey: …)]` references the **column** name (not the property name).

Schema-layer model:

- `ColumnDefinition::$name` — SQL column name.
- `ColumnDefinition::$propertyName` — PHP property name.
- `TableSchema::$columns` and `$reflProperties` are keyed by **column name**
  (matches `information_schema` row keys and SQL-driven access patterns).
- `TableSchema::$pk` — PK **column** name.
- `TableSchema::$pkProp` — PK **property** name.
- `TableSchema::propFor(string $colName): string` — helper that resolves a
  column name to its property name. Use it on code paths that have a column
  name in hand (typically from a `#[Relation]` attribute) and need to access
  the value on a Record instance via PHP property syntax.

**No auto-conversion.** A `camelCase ↔ snake_case` mode (default or opt-in)
is a recurring suggestion and has been deliberately rejected. See
[design-note-no-name-auto-conversion.md](./design-note-no-name-auto-conversion.md)
for the rationale — short version: it creates a refactoring hazard (IDE
"Rename Symbol" silently becomes a schema migration), it removes the literal
column name from the PHP source (hurting `grep` and AI code-comprehension),
and it introduces a derivation rule that has to be remembered everywhere the
column name is read. Explicit per-column `name:` override is the only
sanctioned way to diverge property name from column name.

### `#[Table]` — new fields

| Field       | Default | Purpose                                                                 |
| ----------- | ------- | ----------------------------------------------------------------------- |
| `comment`   | `null`  | Table comment. Both MySQL and Postgres support this (different syntax). |

`#[Table]` carries only **cross-dialect** fields. MySQL-specific options (engine,
charset, collation) live on a separate `#[MysqlTableOptions]` attribute — see
below.

### `#[MysqlTableOptions]` — MySQL-only table options

Class-level attribute read **only** by `MysqlDialect`. Other dialects ignore it
entirely. Every field is nullable so users override only what they care about;
the dialect supplies sensible defaults for any field left null (and for Records
that omit this attribute entirely).

```php
#[Table(name: 'fast_lookup')]
#[MysqlTableOptions(engine: 'Memory')]    // override engine only
final class FastLookup extends Record { ... }
```

| Field       | Dialect default        | Purpose                          |
| ----------- | ---------------------- | -------------------------------- |
| `engine`    | `'InnoDB'`             | MySQL storage engine.            |
| `charset`   | `'utf8mb4'`            | Default charset for the table.   |
| `collation` | `'utf8mb4_unicode_ci'` | Default collation.               |

Defaults live in `MysqlDialect::DEFAULT_ENGINE` / `DEFAULT_CHARSET` /
`DEFAULT_COLLATION` constants — single source of truth.

Future `#[PgsqlTableOptions(tablespace, unlogged, ...)]` will follow the same
pattern; not defined speculatively.

### `MysqlDialect` constructor — instance-level table-option defaults

`new MysqlDialect()` falls back to the `DEFAULT_*` constants for any table that
omits `#[MysqlTableOptions]`. A consumer can override those library defaults per
dialect instance:

```php
$collation = $hostDb->defaultCollation();         // e.g. live DEFAULT_COLLATION_NAME
$charset   = explode('_', $collation, 2)[0];       // charset = collation-name prefix

$dialect = new MysqlDialect(
    defaultCharset: $charset,
    defaultCollation: $collation,
);
```

Each constructor argument is nullable; a null field falls back to the matching
`DEFAULT_*` constant. **Resolution precedence per field** is:

1. per-table `#[MysqlTableOptions]`,
2. the dialect instance default (constructor argument),
3. the `DEFAULT_*` constant.

This lets all generated DDL align with the host database's charset/collation
without annotating every Record — e.g. an adapter creating tables alongside an
existing schema passes that schema's collation, so cross-table string JOINs do
not hit "illegal mix of collations". Deriving the charset from the collation
name (its prefix) keeps the `CHARSET`/`COLLATE` pair valid on any host.

### `#[Relation]` — new fields

| Field      | Default                       | Purpose                                       |
| ---------- | ----------------------------- | --------------------------------------------- |
| `onDelete` | `ForeignKeyAction::Restrict`  | `REFERENCES … ON DELETE` action.              |
| `onUpdate` | `ForeignKeyAction::Restrict`  | `REFERENCES … ON UPDATE` action.              |
| `emitFk`   | `true`                        | Per-relation opt-out for FK constraint emission. |

FK constraints are emitted only for **owning-side** relations:
`ManyToOne` and `OneToOne`. Polymorphic relations (`MorphTo`, `MorphMany`,
`MorphOne`) carry no FK semantics and are always skipped. Inverse-side
relations (`OneToMany`, `OneToOneReversed`) carry no local FK column and are
always skipped.

`ForeignKeyAction` is an enum: `Restrict`, `Cascade`, `SetNull`, `NoAction`, `SetDefault`.

FK constraint names follow the convention `fk_{tableName}_{foreignKeyColumn}`.
If two FKs would collide (same FK column on the same table — should never happen),
schema build throws.

### `#[UniqueKey]` and `#[Index]` — class-level form

Both attributes are now usable at **either** property or class level. Single-column
keys read most naturally as property-level; composites with explicit column
ordering belong at the class level.

```php
// Property-level: declaration order determines composite column order
final class OrderRecord extends Record
{
    #[Column(...)]
    #[UniqueKey('uk_external')]
    public string $external_ref = '';
}

// Class-level: explicit columns list, any ordering
#[Table(name: 'orders')]
#[UniqueKey('uk_customer_date', columns: ['customer_id', 'created_at'])]
#[Index('idx_status_date', columns: ['status', 'created_at'])]
final class OrderRecord extends Record { ... }
```

Rules enforced at schema build:

- Class-level form **requires** `columns: [...]`; property-level form **forbids** it.
- All names listed in `columns` must reference declared `#[Column]` properties.
- A given key name may not be declared in both forms (use one or the other per name).
- Repeating the same name at property level builds a composite in declaration order.

`#[Index]` mirrors `#[UniqueKey]` exactly; the only difference is that it emits
`KEY` rather than `UNIQUE KEY`.

---

## Schema layer

`ColumnDefinition` carries:

- `$name` — SQL column name.
- `$propertyName` — PHP property name (equals `$name` when `name:` is omitted on `#[Column]`).
- `$default`, `$defaultExpr`, `$onUpdate`, `$comment`, `$enumValues` —
  declarative DDL metadata; mutual-exclusion of `default` / `defaultExpr` is
  checked at `TableSchema::fromClass()` time.

`TableSchema` carries:

- `$pk` — primary-key **column** name.
- `$pkProp` — primary-key **property** name (use this for `$record->{$pkProp}` access).
- `$columns` — `array<string, ColumnDefinition>` keyed by **column name**.
- `$reflProperties` — `array<string, \ReflectionProperty>` keyed by **column name** (paired with `$columns`).
- `$uniqueKeys`, `$indexes` — `array<string, list<string>>` mapping key name → ordered column names.
- `$foreignKeys` — `list<ForeignKeyDefinition>` collected from owning-side relations (`ManyToOne`, `OneToOne`) with `emitFk: true`.
- `$comment` — from `#[Table]`.
- `$mysqlOptions` — `?MysqlTableOptions` from the optional `#[MysqlTableOptions]` class-level attribute. Null when the attribute is absent; `MysqlDialect` resolves field-by-field against its own defaults.
- `propFor(string $columnName): string` — resolves a column name to its corresponding property name (used by relation loaders that translate `#[Relation]` column refs to PHP property accessors).

---

## Type rendering (MySQL)

`ColumnType` enum values are already MySQL-spelled, so rendering is mostly
mechanical:

| `ColumnType`         | Rendered                              |
| -------------------- | ------------------------------------- |
| `Int`, `BigInt`, …   | `INT`, `BIGINT`, … (uppercased)       |
| `*Unsigned`          | `BIGINT UNSIGNED`, etc.               |
| `VarChar`, `Char`    | `VARCHAR(n)` — `length` required.     |
| `Binary`, `VarBinary`| `BINARY(n)` / `VARBINARY(n)`.         |
| `Decimal`            | `DECIMAL(p, s)` — both `precision` and `scale` required. |
| `Float`, `Double`    | `FLOAT`, `DOUBLE`.                    |
| `Text*`              | `TINYTEXT`, `TEXT`, `MEDIUMTEXT`, `LONGTEXT`. |
| `Json`               | `JSON`.                               |
| `Enum`, `Set`        | `ENUM('a','b',…)` / `SET('a','b',…)` — `enumValues` required. |
| `Date`                          | `DATE` (no precision; date-only). |
| `DateTime`, `Timestamp`         | `DATETIME(p)` / `TIMESTAMP(p)` when `precision` is set (fractional-seconds, 0-6); bare `DATETIME` / `TIMESTAMP` otherwise. |
| `Bool`               | `TINYINT(1)` (MySQL convention).      |
| `Bit`                | `BIT(n)` if length set, else `BIT`.   |
| `Year`               | `YEAR`.                               |

The `precision:` parameter is shared across types but with type-specific
semantics:

- `Decimal` — total significant digits; **required**, **paired with `scale`**.
- `DateTime`, `Timestamp` — fractional-seconds precision, 0-6; **optional**;
  `scale` is forbidden.
- Any other type — `precision` and `scale` are both forbidden (schema build
  throws), since the values would be silently ignored otherwise.

Validation at schema build:

- `VarChar` / `Char` / `Binary` / `VarBinary` require `length`.
- `Decimal` requires both `precision` and `scale`.
- `DateTime` / `Timestamp` reject `scale`; reject `precision` outside 0-6.
- Non-numeric / non-temporal types reject both `precision` and `scale`.
- `Enum` / `Set` require non-empty `enumValues`.

These already are required in practice; making them mandatory at schema build
surfaces the mistake at startup rather than at CREATE TABLE time.

---

## Column line format

```
`{name}` {TYPE} [NOT NULL] [DEFAULT …] [ON UPDATE …] [AUTO_INCREMENT] [COMMENT '…']
```

Order matches MySQL's preferred ordering. Each clause is omitted if not applicable.
String literals in `DEFAULT` and `COMMENT` are escaped via the same string-escape
routine `MysqlDialect::toLiteral()` already uses.

---

## What's deliberately not in scope

- **`ALTER TABLE` generation** — separate package, see migrations doc.
- **Schema introspection** — separate package.
- **Generated indexes / unique keys from `#[Relation]`** — declare indexes
  explicitly. (MySQL adds an implicit index for the FK column anyway; explicit
  declarations match other concerns like compound query patterns.)
- **Per-column charset / collation** — table-level only for now. Add per-column
  if a real need arises.
- **CHECK constraints** — not modelled in `#[Column]` today; defer.
- **Partial / functional indexes** — out of scope.
- **Tablespace, ROW_FORMAT, key block size, etc.** — out of scope.

---

## Testing strategy

Unit tests in `tests/Unit/MysqlDialectCreateTableTest.php`:

1. **Per-type snapshots** — one fixture Record per representative column shape;
   assert exact SQL strings. Covers nullable/not-null, defaults, enum values,
   bool, decimal, binary, datetime + on-update.
2. **Composite keys** — class-level `#[UniqueKey]` / `#[Index]` with explicit
   `columns`, plus property-level repeated form, both produce expected SQL.
3. **FK constraint emission** — owning-side relations emit constraints;
   inverse-side and morph relations do not.
4. **Validation errors** — `VarChar` without `length`, `Decimal` without scale,
   `Enum` without values, `default` + `defaultExpr` both set: each throws
   `SchemaException` with a clear message.

Integration test (optional, deferred): execute the generated SQL against the
test MySQL container in `docker-compose.yml`, then re-introspect via
`information_schema` and round-trip-compare.

---

## Migration path for existing Records

Existing Records keep working unchanged. The new `#[Column]` / `#[Table]` /
`#[Relation]` fields are all optional with sensible defaults. The only
behavioural change at schema build is the new validation rules for
`VarChar`/`Char`/`Decimal`/`Enum`/`Set` — those weren't enforced before, so any
Record that was relying on missing-length leniency surfaces a clear error.
