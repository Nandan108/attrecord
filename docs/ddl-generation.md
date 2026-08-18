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
//   CONSTRAINT `fk_ec2dc5_orders_customer_id`
//     FOREIGN KEY (`customer_id`) REFERENCES `wp_customers` (`id`)
//     ON DELETE RESTRICT ON UPDATE RESTRICT
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The method lives on `SqlDialect` and both dialects implement it. `PgsqlDialect`
emits the PostgreSQL equivalent of the same schema — see
[PostgreSQL output](#postgresql-output) below.

### Composite primary keys (DDL-only, v0.13+)

`#[Table(primaryKey:)]` names a single column. For a table keyed on two or more —
a junction table, or any "one row per (a, b)" state table — declare it at class level:

```php
#[Table(name: 'article_tag')]
#[PrimaryKey(columns: ['article_id', 'tag_id'])]
final class ArticleTagRecord extends Record { ... }
```

All three dialects emit one `PRIMARY KEY (a, b)` clause. `TableSchema::pkColumns()` returns the
member list (a single-entry list on an ordinary table), and `TableSchema::$compositePk` is
non-null only for these.

Such a Record is **DDL-only**: every CRUD path throws, because they identify a row by a single
`$pk`. That is the point rather than a limitation — the table's reads and writes stay raw SQL,
while its *shape* becomes declared, so the DDL producer emits it and `attrecord-migrations` can
compare it against the live database. A hand-written table is invisible to the differ and drifts
unobserved; this is what makes it visible without pretending the runtime supports composite keys.

### Two seams for evolution tooling (v0.12.0)

Both exist because the `attrecord-migrations` companion needed them against a real schema, and
both are inert unless you ask for them.

**`omitForeignKeys:`** — emit the `CREATE TABLE` with named FK constraints left out:

```php
$sql = $dialect->buildCreateTable($schema, omitForeignKeys: ['fk_b_a_id']);
```

Two tables that reference each other have **no** creation order that works while every FK is
inline — whichever goes first points at a table that does not exist. Omitting one edge of the
loop, creating both tables, then adding the constraint with
`ALTER TABLE … ADD {buildForeignKeyLine($fk)}` resolves it. A name matching no constraint is
ignored, so a caller may pass a set computed across the whole model rather than per table;
omitting nothing is byte-identical to the plain call.

**`TableSchema::extendedWith()`** — describe columns the class cannot:

```php
$schema = TableSchema::fromClass(SlotSpace::class)->extendedWith(
    columns: ['dim_loc' => new ColumnDefinition(name: 'dim_loc', propertyName: 'dim_loc', /* … */)],
    indexes: ['idx_active_loc' => ['active', 'dim_loc', 'id']],
);
```

For a table whose shape is only known at runtime — a registry with a column per registered
dimension, a plugin's extension columns. A *derivation*, not construction from scratch: the
Record stays the source of truth for the table name, primary key, foreign keys and reflection
data, so the result is an ordinary schema that happens to carry extra columns. Adding a name the
class already declares throws. Without it, such columns can only exist as a hand-written `ALTER`
that no schema tooling can see, diff or verify.

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

### FK constraint naming — and why several installs may share one database

Constraint names follow `fk_{prefixDigest}_{tableName}_{foreignKeyColumn}`, where `{prefixDigest}`
is the first six hex characters of `sha256(Record::tablePrefix())` and `{tableName}` has that prefix
removed. An **unprefixed** install omits the digest entirely and gets the plain
`fk_{tableName}_{foreignKeyColumn}`. So `wp_orders.customer_id` yields
`fk_ec2dc5_orders_customer_id`, and `ps_orders.customer_id` yields `fk_2f9391_orders_customer_id`.

Both naming paths are covered — `#[Relation]`-derived keys and class-level `#[ForeignKey]`
(constraint-only) keys alike.

**Why the digest is there.** InnoDB scopes constraint names **per database, not per table**. Two
installs can legitimately share one database — a platform cutover running two hosts against it
during a transition, or two WordPress sites at `wp_` and `blog_` on shared hosting — and without the
prefix in the name both derive the same one, so the second `CREATE TABLE` fails with errno 121,
*"duplicate key on write or update"*.

**Several prefixed installs in one database is therefore supported on MySQL and MariaDB from
0.16.0**; before that it silently was not. Scoping, all verified rather than inferred:

| Identifier | MySQL / MariaDB | PostgreSQL |
| --- | --- | --- |
| FK constraint name | **per database** — hence the digest | per table |
| CHECK constraint name (`#[Check]`, and the enum columns' own on PG/SQLite) | **per database** — hence the digest, see `#[Check]` below | per table |
| index / unique-key name | per table | **per schema** |

**On PostgreSQL the prefix is not sufficient**, and this is not something the digest can fix: index
and unique-key names are *yours* (`#[Index('idx_name')]`, `#[UniqueKey('uniq_sku')]`) and live in the
schema-wide relation namespace, so a second install in the same schema fails with
`relation "uniq_sku" already exists` before any constraint name is reached. The idiomatic separation
on PostgreSQL is a **schema per install** (`search_path`), which sidesteps the question entirely.

**Why a digest rather than the prefix itself.** Including the prefix verbatim also makes the name
unique, but then its length grows with the prefix and overflows the 64-character identifier limit on
a long or hardened one — and hardening guidance actively recommends long random prefixes. A
fixed-width digest costs the same seven characters whatever the prefix.

**Long names fold rather than overflow.** Even with the digest, a long table plus a long column can
exceed 64. Past the limit the *column* is replaced by a 10-hex digest of `table.column` and the
table name is kept — the more useful half when reading an error message. Deterministic, and provably
within the limit for any input.

If two FKs would collide (same FK column on the same table — should never happen), schema build
throws.

### `#[ForeignKey]` — constraint-only foreign keys (class-level, repeatable)

Emits a FOREIGN KEY constraint with **no relation property**. The attribute goes on the
*referencing* table (a normal Record, whose own DDL is generated as usual); its `references`
parameter names the **target**, which may be either:

- a **table name** — for a target attrecord doesn't model (hand-written raw-SQL DDL, or an
  externally owned table); `referencesColumn` names the target column (default `id`); or
- a **Record class-string** — the target table name *and* its primary key are derived from
  that Record (rename-safe), with no relation to hydrate.

Use `#[Relation]` when you also want object hydration of the target; `#[ForeignKey]` is the
constraint-only form — the only option when the target has no Record, and a clean
relation-free way to declare *every* FK on a **DDL-only Record** (one whose rows are
read/written by raw SQL, so it has no relations to hydrate) using the class-string form for
Record-backed targets and the table-name form for the rest.

```php
#[Table(name: 'invflux_inventory_ledger')]
#[ForeignKey(column: 'subject_id', references: Subject::class, onDelete: ForeignKeyAction::Restrict)]
#[ForeignKey(column: 'from_slot_id', references: 'invflux_slotspace', onDelete: ForeignKeyAction::SetNull)]
final class InventoryLedger extends Record { /* … */ }
```

| Field              | Default                      | Purpose                                                                          |
| ------------------ | ---------------------------- | -------------------------------------------------------------------------------- |
| `column`           | —                            | Local FK column (must be a declared `#[Column]`).                                |
| `references`       | —                            | Target **table base name** (un-prefixed) **or** a target **Record class-string**. |
| `referencesColumn` | `'id'`                       | Target column — used with the table-name form; ignored for a class (its PK is used). |
| `onDelete`         | `ForeignKeyAction::Restrict` | `REFERENCES … ON DELETE` action.                                                 |
| `onUpdate`         | `ForeignKeyAction::Restrict` | `REFERENCES … ON UPDATE` action.                                                 |

The target is resolved lazily at DDL-build time via `ForeignKey::references()` /
`ForeignKey::referencesColumn()`: a class form resolves to the target Record's table + PK; a
table-name form has the active prefix (`Record::tablePrefix()`) applied — so either resolves
correctly under a prefix. Constraint naming and the duplicate-FK-column guard are shared with
`#[Relation]` FKs (a column used by both a `#[Relation]` and a `#[ForeignKey]`, or a
`#[ForeignKey]` on an undeclared column, throws at schema build).

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

### `#[Check]` — table-level CHECK constraints (class-level, repeatable)

A boolean expression every row must satisfy, emitted into `CREATE TABLE` on all three dialects:

```php
#[Table(name: 'subjects')]
#[Check('tracking_unit_only', "kind = 'unit' OR tracking = 'none'")]
#[Check('batch_has_parent', "kind <> 'batch' OR parent_id IS NOT NULL")]
final class Subject extends Record { ... }
```

The expression is **passed to the engine verbatim** — nothing here parses it, which is what makes
each engine's full expression language available and equally makes portability the author's
business. An expression using a MySQL-only function fails at `CREATE TABLE` on PostgreSQL, loudly,
which is the right moment to find out.

Refused at schema-build time: a repeated name, an empty name or expression, and a name colliding
with the `chk_<column>_enum` constraint that carries an enum column's members on PostgreSQL and
SQLite (taking that name would replace the member list with a rule and drop the enum's enforcement
on those two backends).

**What a CHECK can and cannot be.** It is row-local: it sees one row's columns, cannot query
another table or another row (no engine permits a subquery here), and is not evaluated against rows
that already exist until something rewrites them. Rules spanning rows stay in the application, with
the CHECK carrying the single-row *projection* of the rule — defence against writes that never pass
through the application at all: a CLI, a neighbouring plugin, someone at a SQL prompt.

**Enforcement is version-dependent, in one direction that matters.** MySQL enforces from 8.0.16;
**8.0.0–8.0.15 parse the clause and ignore it**, so the DDL succeeds and the guarantee is simply
absent. MariaDB enforces from 10.2.1, PostgreSQL and SQLite always. A consumer that cannot pin its
host's version — a WordPress plugin, say — should treat a CHECK as a backstop, never as the only
guard.

#### CHECK constraint naming

The emitted name is `chk_{scopeDigest}_{declaredName}_{expressionDigest}`, so
`#[Check('tracking_unit_only', …)]` becomes something like
`chk_d138a3_tracking_unit_only_9f2e11`. Two digests, for two unrelated problems:

- **The scope digest** covers the table prefix *and* the table, because MySQL scopes CHECK
  constraint names **per database** (`ERROR 3822 Duplicate check constraint name`) where every other
  supported engine scopes them per table. The prefix half is the foreign-key story exactly — two
  installs sharing one database must not derive the same name. The table half is what a foreign key
  gets for free by carrying the table name in the clear: without it the *same rule on two tables*
  collides inside one install, which `#[Check('qty_non_negative', 'qty >= 0')]` on two different
  line tables would do immediately. Digested rather than spelled out so the name stays within the
  limit for any table name; the cost is that a violation message names the rule but not the table.
- **The expression digest** has no foreign-key equivalent. No engine gives the expression back as
  written — MySQL re-prints it with charset introducers and its own brackets, PostgreSQL adds
  `::text` casts — so schema tooling comparing live against declared cannot distinguish *the author
  changed the rule* from *the engine spells it differently*. The fail-safe reading of that ambiguity
  (assume the engine) is the one that would silently withhold a corrected rule from every database
  that has the old one. Digesting the expression into the name removes the comparison from the
  problem: an edited expression **is** a differently-named constraint, so name-only convergence adds
  the new one and drops its predecessor. Whitespace is normalized first, so re-indenting an
  expression is not a schema change.

Long declared names fold rather than overflow the 64-character limit: the name is truncated, both
digests kept.

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
- `$foreignKeys` — `list<ForeignKeyDefinition>` collected from owning-side relations (`ManyToOne`, `OneToOne`) with `emitFk: true`, plus any class-level `#[ForeignKey]` (Record-less FKs). A `ForeignKeyDefinition` resolves its target through `targetTableName()` / `targetColumnName()` — lazily from the target Record for the relation form, or from the explicit (prefix-applied) table + column for the `#[ForeignKey]` form.
- `$checks` — `array<string, CheckDefinition>` from class-level `#[Check]`, keyed by the **emitted** constraint name (what the database and any schema tooling see). Each definition also carries `$declaredName`, the name as written in the attribute, for error messages that should quote the author back to themselves.
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

## PostgreSQL output

`PgsqlDialect::buildCreateTable()` emits the PostgreSQL equivalent from the **same**
attributes. Differences from the MySQL output:

| Concern | MySQL | PostgreSQL |
| --- | --- | --- |
| Auto-increment PK | `BIGINT UNSIGNED … AUTO_INCREMENT` | `BIGSERIAL` (implies NOT NULL + sequence default) |
| Unsigned integers | `INT UNSIGNED`, etc. | no unsigned — widened to `SMALLINT`/`INTEGER`/`BIGINT` |
| `Bool` | `TINYINT(1)` | `BOOLEAN` |
| `Decimal` | `DECIMAL(p,s)` | `NUMERIC(p,s)` |
| `Binary`/`VarBinary` | `BINARY(n)`/`VARBINARY(n)` | `BYTEA` |
| `Json` | `JSON` | `JSONB` |
| `Enum` | `ENUM('a','b')` | `TEXT` + `CONSTRAINT chk_col_enum CHECK (col IN ('a','b'))` |
| `Char`/`VarChar`/`Text`* | same | `CHAR(n)`/`VARCHAR(n)`/`TEXT` |
| Secondary indexes | inline `KEY` | trailing `CREATE INDEX` statements |
| Table / column comments | inline / `COMMENT=` | trailing `COMMENT ON TABLE` / `COMMENT ON COLUMN` |
| Table options | `ENGINE`/`CHARSET`/`COLLATE` | omitted (no equivalent) |
| `ON UPDATE` column clause | emitted | omitted (needs a trigger in PG) |
| `Set` type | `SET('a','b')` | rejected — `SchemaException` |
| `VIRTUAL` generated column | `… VIRTUAL` | rejected — `SchemaException` (PG <18 has STORED only) |

Because PG cannot declare indexes/comments inline, `buildCreateTable()` returns a
**multi-statement** string (statements separated by `;\n`) — the `CREATE TABLE` followed by
any `CREATE INDEX` / `COMMENT ON` statements. The whole batch is safe to run in a single
`PDO::exec()`. `ifNotExists: true` additionally emits `CREATE INDEX IF NOT EXISTS`.

```php
$sql = (new PgsqlDialect())->buildCreateTable(TableSchema::fromClass(OrderRecord::class), ifNotExists: true);
// CREATE TABLE IF NOT EXISTS "wp_orders" (
//   "id" BIGSERIAL,
//   "customer_id" BIGINT NOT NULL,
//   "status" VARCHAR(20) NOT NULL DEFAULT 'pending',
//   ...
//   PRIMARY KEY ("id"),
//   CONSTRAINT "uk_orders_external" UNIQUE ("external_ref"),
//   CONSTRAINT "fk_ec2dc5_orders_customer_id" FOREIGN KEY ("customer_id")
//     REFERENCES "wp_customers" ("id") ON DELETE RESTRICT ON UPDATE RESTRICT
// );
// CREATE INDEX IF NOT EXISTS "idx_orders_status" ON "wp_orders" ("status", "created_at")
```

> `generatedAs` expressions are raw SQL and therefore dialect-specific. Prefer portable
> functions (e.g. `COALESCE` over MySQL's `IFNULL`) when a Record's DDL must build on both
> engines.

---

## What's deliberately not in scope

- **`ALTER TABLE` generation** — separate package; designed in
  [arch-migrations.md](arch-migrations.md) (the `attrecord-migrations` companion).
- **Schema introspection** — same separate package (the converge pipeline's live side).
- **Generated indexes / unique keys from `#[Relation]`** — declare indexes
  explicitly. (MySQL adds an implicit index for the FK column anyway; explicit
  declarations match other concerns like compound query patterns.)
- **Per-column charset / collation** — table-level only for now. Add per-column
  if a real need arises.
- **Column-level CHECK constraints** — a CHECK is declared on the class, not the column (see
  `#[Check]` above); a single-column rule is written as a table-level one naming that column.
- **Partial / functional indexes** — out of scope.
- **Tablespace, ROW_FORMAT, key block size, etc.** — out of scope.

---

## Testing strategy

Unit tests in `tests/Unit/MysqlDialectCreateTableTest.php` and
`tests/Unit/PgsqlDialectCreateTableTest.php`:

1. **Per-type snapshots** — one fixture Record per representative column shape;
   assert exact SQL strings. Covers nullable/not-null, defaults, enum values,
   bool, decimal, binary, datetime + on-update (and their PostgreSQL equivalents).
2. **Composite keys** — class-level `#[UniqueKey]` / `#[Index]` with explicit
   `columns`, plus property-level repeated form, both produce expected SQL.
3. **FK constraint emission** — owning-side relations emit constraints;
   inverse-side and morph relations do not.
4. **Validation errors** — `VarChar` without `length`, `Decimal` without scale,
   `Enum` without values, `default` + `defaultExpr` both set: each throws
   `SchemaException` with a clear message (plus PG-only `Set` / `VIRTUAL` rejections).

**Integration:** the dual-backend integration suites (`tests/Integration/`) no longer
hand-write `CREATE TABLE`; each suite's schema is generated from the fixtures' attributes via
`buildCreateTable()` and executed against the live MySQL and PostgreSQL containers from
`docker-compose.yml` — so the DDL producer is exercised end-to-end on both engines on every run.

---

## Migration path for existing Records

Existing Records keep working unchanged. The new `#[Column]` / `#[Table]` /
`#[Relation]` fields are all optional with sensible defaults. The only
behavioural change at schema build is the new validation rules for
`VarChar`/`Char`/`Decimal`/`Enum`/`Set` — those weren't enforced before, so any
Record that was relying on missing-length leniency surfaces a clear error.
