# attrecord — LLM Reference

Comprehensive, AI-ingestion-oriented reference for the `nandan108/attrecord` library. Aimed
at completeness and precise shapes rather than narrative — read [README.md](../README.md) for
prose and worked examples, and the topic docs in this directory for deep dives
([column-casting](column-casting.md), [ddl-generation](ddl-generation.md),
[where-clause](where-clause.md), [polymorphic-relations](polymorphic-relations.md)).

## Reading guide

- **What it is.** A lightweight, attribute-driven active-record layer for PHP 8.1+. Declare
  schema with PHP attributes; the same metadata drives CRUD, dirty-tracking, batch upserts,
  eager relation loading, deadlock-safe locking, and `CREATE TABLE` DDL emission.
- **No runtime dependencies.** Psalm level 1 clean. Namespace root `Nandan108\Attrecord\`,
  PSR-4 from `src/`.
- **Three supported SQL dialects: MySQL/MariaDB, PostgreSQL, and SQLite (>= 3.33).** A
  `SqlDialect` abstraction isolates the differences; the same Record code runs on all three.
  SQLite is a first-class target as of v0.2.0 (requires SQLite >= 3.33 for `UPDATE … FROM` in
  the bulk-upsert join). See [§13 Dialect portability](#13-dialect-portability).
- **Constructor params with `?` are nullable; param order matches the listed constructor.**
  All attribute properties are `readonly`.
- **Conventions.** Column SQL type comes from `ColumnType`; PHP property type is whatever you
  declare. snake_case SQL ↔ camelCase PHP is opt-in per column via `name:` (no auto-conversion).

---

## 1. Package map / namespaces

| Namespace | Contents |
|---|---|
| `Nandan108\Attrecord` | `Record`, `RecordSet`, `WhereClause`, `RawSql`, `Connection`, `DbSession`, `SqlDialect`, `ColumnSerializer` (internal), `ColumnCaster`, `JsonCastable`, `BinaryParam`, `LockSet`, `Transaction`, `SaveResult`, `UpsertSql`, `NamedPlaceholderSql` (internal) |
| `Nandan108\Attrecord\Attribute` | `Table`, `Column`, `ForeignKey`, `Index`, `UniqueKey`, `Relation`, `LockTier`, `MysqlTableOptions`, `Cast` (abstract base) |
| `Nandan108\Attrecord\Caster` | `DateTimeCaster`, `EpochCaster`, `JsonCaster`, `EnumCaster` |
| `Nandan108\Attrecord\Dialect` | `MysqlDialect`, `PgsqlDialect`, `SqliteDialect`, `UpsertJoinBuilder` (trait) |
| `Nandan108\Attrecord\Session` | `PdoDbSession`, `MysqliDbSession`, `WpDbSession`, `RetryingDbSession` |
| `Nandan108\Attrecord\Schema` | `TableSchema`, `ColumnDefinition`, `ForeignKeyDefinition`, `RelationDefinition` |
| `Nandan108\Attrecord\Enum` | `ColumnType`, `RelationType`, `ForeignKeyAction`, `GeneratedColumnMode` |
| `Nandan108\Attrecord\Exception` | see [§12](#12-exceptions) |
| `Nandan108\Attrecord\Test` | `CapturingDbSession` (test utility) |

---

## 2. Bootstrap

```php
use Nandan108\Attrecord\{Connection, Record};
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\Attrecord\Dialect\MysqlDialect;   // or PgsqlDialect

$conn = new Connection(new PdoDbSession($pdo), new MysqlDialect());
Record::setConnection($conn);          // global default for all Records
Record::setTablePrefix('wp_');         // optional; prepended to every Table name
```

- `Connection` is a `final` value object: `public readonly DbSession $session`,
  `public readonly SqlDialect $dialect`. Its constructor runs
  `$dialect->connectionInitStatements()` against the session (per-connection baseline setup —
  e.g. SQLite `PRAGMA journal_mode=WAL` / `busy_timeout` / `foreign_keys`; an empty no-op for
  MySQL/MariaDB and PostgreSQL).
- `Record::setConnection(Connection $connection, ?string $forClass = null)` — `$forClass`
  registers a per-class connection override (multi-DB); omit for the global default.
- `Record::connection(): Connection`, `Record::tablePrefix(): string`,
  `Record::setTablePrefix(string $prefix): void`.
- The table prefix is read **fresh at query/DDL time**, so changing it re-targets all Records
  (used by the test suite and multi-tenant setups).

---

## 3. Defining a Record

```php
use Nandan108\Attrecord\Attribute\{Table, Column, Relation, UniqueKey, Index};
use Nandan108\Attrecord\Enum\{ColumnType, RelationType};
use Nandan108\Attrecord\{Record, RecordSet};

#[Table(name: 'orders', primaryKey: 'id', comment: 'Customer orders')]
#[UniqueKey('uk_external', columns: ['external_ref'])]
#[Index('idx_status_date', columns: ['status', 'created_at'])]
final class Order extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $customer_id = 0;

    #[Column(ColumnType::VarChar, length: 100, name: 'display_name')]  // SQL column `display_name`; PHP property stays $displayName
    public string $displayName = '';

    #[Relation(RelationType::ManyToOne, class: Customer::class, foreignKey: 'customer_id')]
    public ?Customer $customer = null;
}
```

Rules:
- A Record **must** extend `Record` and carry a `#[Table]` attribute.
- Every persisted column is a **public property** with a `#[Column]` attribute. Properties
  without `#[Column]` are ignored by persistence (e.g. relation properties).
- The PK property is identified by `#[Table(primaryKey: …)]` (default `'id'`).
- `name:` on `#[Column]` overrides the SQL column name; the PHP property name is unchanged.
  There is **no** automatic snake_case↔camelCase conversion
  ([design note](design-note-no-name-auto-conversion.md)).

---

## 4. Attribute catalogue

All attributes are `readonly`. Param order below matches the constructor.

### `#[Table]` (class-level, required)
| Param | Type | Default | Notes |
|---|---|---|---|
| `name` | `string` | — | Table name (before prefix). |
| `primaryKey` | `string` | `'id'` | PK **property** name. |
| `comment` | `?string` | `null` | Cross-dialect table comment (DDL). |

### `#[Column]` (property-level)
| Param | Type | Default | Notes |
|---|---|---|---|
| `type` | `ColumnType` | — | SQL type (see [§5](#5-columntype)). |
| `name` | `?string` | `null` | SQL column name override (defaults to property name). |
| `nullable` | `bool` | `false` | Allows NULL. |
| `autoIncrement` | `bool` | `false` | Sequence/identity PK; skipped in INSERT/UPDATE; back-filled after INSERT. |
| `trimOnSave` | `?bool` | `null` | Trim string on save; whitespace-only changes don't mark dirty. |
| `length` | `?int` | `null` | `VarChar`/`Char`/`Binary`/`VarBinary`/`Bit`. Required for `VarChar`/`Char` at DDL build. |
| `precision` | `?int` | `null` | `Decimal`: total digits (required, with `scale`). `DateTime`/`Timestamp`: fractional-seconds 0–6 (optional). |
| `scale` | `?int` | `null` | `Decimal` scale (required); forbidden elsewhere. |
| `default` | `int\|float\|string\|bool\|null` | `null` | Literal DEFAULT (DDL). Mutually exclusive with `defaultExpr`. |
| `defaultExpr` | `?string` | `null` | Raw SQL DEFAULT expression (e.g. `'CURRENT_TIMESTAMP'`). |
| `onUpdate` | `?string` | `null` | Raw SQL `ON UPDATE` (MySQL DDL only; ignored by PG). |
| `comment` | `?string` | `null` | Column comment (DDL). |
| `enumValues` | `?list<string>` | `null` | Required for `Enum`/`Set`. |
| `generatedAs` | `?string` | `null` | Raw SQL expression → `GENERATED ALWAYS AS (...)`. Column excluded from writes. **Dialect-specific SQL.** |
| `generatedMode` | `?GeneratedColumnMode` | `null` | `Stored` / `Virtual`. PG supports `Stored` only. |

Property-level `#[UniqueKey('name')]` / `#[Index('name')]` (no `columns`) attach the property's
column to a single-column key.

### `#[CreatedAt]` / `#[UpdatedAt]` (property-level, on a DateTime/Timestamp column)

Auto-managed timestamps, typed `?\DateTimeImmutable`. `#[CreatedAt]` is set on INSERT only;
`#[UpdatedAt]` is set on INSERT and bumped on any UPDATE that changes another column (a clean save
does not bump it). At most one of each per Record. Applied in `save()` and `saveAll()`.

### `#[UniqueKey]` / `#[Index]`
| Param | Type | Default | Notes |
|---|---|---|---|
| `name` | `string` | — | Key/index name. |
| `columns` | `?list<string>` | `null` | Class-level: list the columns. Property-level: must be `null`. |

### `#[Relation]` (property-level)
| Param | Type | Default | Notes |
|---|---|---|---|
| `type` | `RelationType` | — | See [§6](#6-relations). |
| `class` | `?string` | `null` | Target Record FQCN (not required for `MorphTo`, which uses `morphMap`). |
| `foreignKey` | `?string` | `null` | FK column (owning or related side per type). |
| `localKey` | `?string` | `null` | Local key column when not the PK. |
| `morphType` | `?string` | `null` | Type-discriminator column (morph relations). |
| `morphKey` | `?string` | `null` | Id column (morph relations). |
| `morphValue` | `int\|string\|null` | `null` | This Record's discriminator value (`MorphMany`/`MorphOne`). |
| `morphMap` | `?array<string,class-string>` | `null` | Discriminator→class map (`MorphTo`). |
| `onDelete` | `ForeignKeyAction` | `Restrict` | FK action (DDL). |
| `onUpdate` | `ForeignKeyAction` | `Restrict` | FK action (DDL). |
| `emitFk` | `bool` | `true` | Whether an owning-side relation emits a FK constraint in DDL. |

### `#[ForeignKey]` (class-level — constraint-only, no relation property)
| Param | Type | Default | Notes |
|---|---|---|---|
| `column` | `string` | — | Local FK column (must be a declared `#[Column]`). |
| `references` | `string` | — | Target: a Record FQCN **or** a literal table name. |
| `referencesColumn` | `string` | `'id'` | Target column. |
| `onDelete` | `ForeignKeyAction` | `Restrict` | |
| `onUpdate` | `ForeignKeyAction` | `Restrict` | |

### `#[LockTier]` (class-level)
| Param | Type | Notes |
|---|---|---|
| `tier` | `int` | Lock-ordering tier for `LockSet::acquire()` (ascending). |

### `#[MysqlTableOptions]` (class-level, MySQL-only; PG ignores it)
| Param | Type | Default (via `MysqlDialect`) |
|---|---|---|
| `engine` | `?string` | `InnoDB` |
| `charset` | `?string` | `utf8mb4` |
| `collation` | `?string` | `utf8mb4_unicode_ci` |

### `#[Cast]` family — see [§9](#9-column-casting).

---

## 5. ColumnType

Enum `Nandan108\Attrecord\Enum\ColumnType` (backed by string). PHP type ← → SQL type:

| PHP type | `ColumnType` cases | MySQL SQL | PostgreSQL SQL | SQLite SQL (affinity) |
|---|---|---|---|---|
| `int` | `TinyInt`,`SmallInt`,`MediumInt`,`Int`,`BigInt` (+`*Unsigned`), `Year`, `Bit` | as named (+`UNSIGNED`) | `SMALLINT`/`INTEGER`/`BIGINT` (no unsigned); `BIT(n)` | `INTEGER` (all int/year/bit cases) |
| `bool` | `Bool` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` (`1`/`0`) |
| `float` (or exact `string`) | `Float`,`Double`,`Decimal` | `FLOAT`/`DOUBLE`/`DECIMAL(p,s)` | `REAL`/`DOUBLE PRECISION`/`NUMERIC(p,s)` | `REAL`/`REAL`/`NUMERIC` |
| `string` | `Char`,`VarChar`,`TinyText`,`Text`,`MediumText`,`LongText`,`Json`,`Enum`,`Set`,`Binary`,`VarBinary` | as named | `CHAR(n)`/`VARCHAR(n)`/`TEXT`/`JSONB`/`TEXT`+CHECK/(Set→error)/`BYTEA` | `TEXT` (Char/VarChar/text family/Json/Enum+CHECK); `Set`→error; `Binary`/`VarBinary`→`BLOB` |
| `\DateTimeImmutable` | `Date`,`DateTime`,`Timestamp` | `DATE`/`DATETIME(p)`/`TIMESTAMP(p)` | `DATE`/`TIMESTAMP(p)` | `TEXT` (ISO-8601; fractional secs when `precision`) |

Derived predicates on `ColumnType`: `isInteger()`, `isBool()`, `isFloat()`, `isNumeric()`,
`isBinary()`, `isDateTime()`, `isDate()`, `isString()`.

Type notes:
- A `Decimal`/`Float` column bound to a **`string`-typed property** is read/written as its
  exact decimal string (no lossy float round-trip).
- `DateTime`/`Timestamp` honor declared `precision` (fractional seconds) on both read and write.
- `Bool` reads normalize MySQL `1/0` and PostgreSQL `t/f` to PHP `bool`.
- `Binary`/`VarBinary` hold raw bytes; see [§10 BinaryParam](#10-binary-values-binaryparam).

---

## 6. Relations

`RelationType`: `OneToMany`, `ManyToOne`, `OneToOne`, `OneToOneReversed`, `MorphMany`,
`MorphOne`, `MorphTo`, `ManyToMany`, `HasManyThrough`.

| Type | FK location | PHP property type | Required params |
|---|---|---|---|
| `OneToMany` | related table | `?RecordSet<T>` | `class`, `foreignKey` |
| `ManyToOne` | this table | `?T` | `class`, `foreignKey` |
| `OneToOne` | this table | `?T` | `class`, `foreignKey` |
| `OneToOneReversed` | related table | `?T` | `class`, `foreignKey` |
| `MorphMany` | related table | `?RecordSet<T>` | `class`, `morphType`, `morphKey`, `morphValue` |
| `MorphOne` | related table | `?T` | `class`, `morphType`, `morphKey`, `morphValue` |
| `MorphTo` | this table | union `?T` | `morphType`, `morphKey`, `morphMap` |
| `ManyToMany` | pivot table | `?RecordSet<T>` | `class`, `pivotTable`, `pivotLocalKey`, `pivotForeignKey` |
| `HasManyThrough` | via intermediate | `?RecordSet<T>` | `class`, `through`, `foreignKey`, `secondKey` |

`ManyToMany` resolves as a two-hop `IN(…)` (pivot rows, then targets by PK); it is pivot-less — for
pivot-column data, model the junction as its own Record and traverse a `OneToMany → ManyToOne`
chain. `HasManyThrough` reaches the far records via the intermediate without hydrating it
(`foreignKey` = through→local, `secondKey` = far→through; `localKey`/`throughKey` default to PKs).

Loaded imperatively via `RecordSet::load('relation')`, dot-paths `load('posts.user')`, or several
paths at once `load('a.b', 'a.c')` (shared prefixes load once). Batched — one `IN(…)` query per
relation level, no N+1. `loadMissing(...)` is the skip-if-already-loaded variant; `with()` is a
deprecated alias for `load()`. See [polymorphic-relations.md](polymorphic-relations.md).

---

## 7. Record API

Static finders:
- `getOne(int|string $id, bool $forUpdate = false, ?Transaction $tx = null): ?static`
- `getOneOrFail(int|string $id): static` — throws `RecordNotFoundException`.
- `getOneOrNew(int|string $id): static` — new instance with PK set if not found.
- `find(string|WhereClause $where = '', array $params = [], string $orderByLimit = '', bool $forUpdate = false, ?Transaction $tx = null): RecordSet<static>`
- `findOne(string|WhereClause $where, array $params = [], string $orderByLimit = 'LIMIT 1', bool $forUpdate = false): ?static`
- `where(string $column, mixed $value, string $op = '='): RecordSet<static>` — column auto-quoted.
- `whereIn(string|list<string> $column, array $values): RecordSet<static>` — single or composite.
- `whereInTuples(array $columns, array $rows): RecordSet<static>` — row-value-constructor IN, rendered as `((c1, c2) IN ((?, ?), …))`. Dialect-independent; supported on all three backends (SQLite has row-value IN since 3.15, well under the 3.33 floor).
- `countWhere(string|WhereClause $where, array $params = []): int`
- `updateWhere(array $set, string|WhereClause $where = '', array $params = []): int` — bulk UPDATE.
- `deleteWhere(string|WhereClause $where, array $params = []): int` — bulk DELETE.
- `firstOrNew(array $match, array $defaults = []): static` — first record matching `$match`
  (AND-ed column equality, non-empty), or an **unsaved** `new` with `$match + $defaults`.
- `findOrCreate(array $match, array $defaults = []): static` — like `firstOrNew` but **saves** a new record.
- `updateOrCreate(array $match, array $values): static` — find-and-update or create; always persisted.

Construction / mutation:
- `newWith(array $attrs): static` — construct + `set()`.
- `set(array $attrs, bool $validate = true): static` — assign + optional `validate()`.
- `validate(): void` — override to enforce invariants (throw `RecordValidationException`).
  Called from `set()` and at save time.

Lifecycle hooks (override; empty by default):
- `beforeSave(): void` / `afterSave(bool $wasInsert): void` — around INSERT/UPDATE. `afterSave`
  fires only on an actual write (single `save()` and per record in `saveAll()`), not a clean no-op.
- `beforeDelete(): void` / `afterDelete(): void` — around single `delete()` (bulk `deleteAll()` skips them).
- `afterLoad(): void` — after each hydration from a DB row.

Persistence:
- `save(bool $force = false): static` — INSERT if new, else UPDATE of **dirty** columns only.
  Returns `$this`; `->_saved` (bool) reflects whether a write occurred. `force` writes even if clean.
- `delete(): void` — DELETE by PK; marks record new again.
- `upsertByUniqueKey(string $conflictKey, array $updateColumns, bool $preserveAutoIncrement = false): void`
  — single-row upsert on a unique key. `preserveAutoIncrement: true` uses a SELECT-then-write
  strategy that does **not** burn an auto-increment value on conflict.
- `updateByUniqueKey(array $fields = []): int` — UPDATE keyed by this record's unique key.
- `updateByWhere(string|WhereClause $where = '', array $params = [], array $fields = []): int`
- `reload(): void` — re-fetch by PK, refresh properties + snapshot.
- `load(string ...$relationPaths): static` / `loadMissing(string ...$relationPaths): static` —
  single-record relation loading; wraps `$this` in a one-element set and delegates to the
  `RecordSet` equivalents (same variadic / dot-path / shared-prefix semantics).

Dirty tracking / state:
- `isDirty(string ...$fields): bool` — any (or named) columns changed since load/save.
- `dirtyFields(string ...$fields): array<string,array{0:mixed,1:mixed}>` — column → `[old,new]`.
- `isNew(): bool`
- `markClean(): void` — snapshot current values as clean.
- `hydrateFromRow(array $row): void` — internal hydration (raw DB row → typed properties + snapshot).
- `hydrateFromArray(array $data): static` (static) — build a "loaded" instance for tests; bypasses validation.
- `toRawArray(): array<string,scalar|null>` — column → DB-bound scalar (binary unwrapped to bytes).

Transactions / schema:
- `transactional(\Closure $operation): mixed` (static) — run in a transaction; nests into an outer tx.
- `schema(): TableSchema` (static), `connection(): Connection` (static).

---

## 8. RecordSet API

`RecordSet<T>` implements `ArrayAccess`, `Countable`, `Iterator`. Construct with
`new RecordSet(array $records = [])`.

Access / shaping:
- `first(): ?T`, `last(): ?T`
- `pluck(string|list<string> $fields, string ...$keys): array` — keyed by PK (no `$keys`) or
  grouped by `$keys`. Single field → scalar leaf; multiple → assoc leaf.
- `recordsByKey(string $field): array<scalar,T>`
- `recordsGroupedByKey(string $field): array<scalar,list<T>>`
- `recordsGroupedByKeys(string $key, string ...$additionalKeys): array` — nested groups; leaves are `RecordSet`.
- `toArraySet(): list<T>`
- `bulkSet(array $attrs): static` — assign attrs to every record (stages dirty); returns `$this`.

Batch persistence (single SQL per operation — never a loop of queries):
- `saveAll(bool $force = false, ?int $chunkSize = null, bool $allowInTransactionChunking = false): ?SaveResult`
  — plain bulk `INSERT` for PK-null records; deadlock-safe 3-step upsert for keyed records
  (INSERT-IGNORE/ON-CONFLICT-DO-NOTHING → `SELECT … FOR UPDATE` ascending-PK → join-based
  `UPDATE`). Back-fills auto-increment PKs onto new records (PG/SQLite via `RETURNING`, MySQL via
  `lastInsertId()` + sequential range). Returns `null` if nothing was dirty. `force: true` saves
  clean records too. **Runs the full per-record lifecycle — `beforeSave()` then `validate()` are
  called on every dirty record before the write** (exactly like `save()`), so it is *not* a raw
  CASE-UPDATE that bypasses Record hooks: a per-row `save()` loop can be replaced by one `saveAll()`
  with no loss of validation or timestamp-stamping. **Chunking:**
  - `$chunkSize === null` (default) — the whole set runs in **one transaction**, all-or-nothing
    (unchanged v0.1 behaviour).
  - `$chunkSize` int — split the write into `$chunkSize`-row slices that **commit independently**,
    bounding the lock/undo footprint for very large batches at the cost of whole-set atomicity
    (a mid-run failure leaves earlier chunks committed, so the operation must be **resumable**;
    each chunk is `markClean()`ed as it commits). Keyed records are **PK-sorted ascending before
    chunking** so each chunk's step-2 `FOR UPDATE` locks a contiguous ascending range and chunks
    proceed low→high — preserving the global ascending-PK lock-order invariant. New (PK-null)
    records are chunked for INSERT first, then keyed chunks.
  - Issuing a chunked write **inside an open transaction** throws `AttrecordException` (per-chunk
    commit is impossible — the outer transaction holds every lock until it commits) **unless**
    `$allowInTransactionChunking: true`, which chunks the statements inline within the outer
    transaction: smaller statements, still **atomic** (the outer transaction's contract), but the
    lock/undo footprint stays unbounded. No effect outside a transaction.
- `upsertAllByUniqueKey(string $conflictKey): ?SaveResult` — bulk burn-free upsert by unique key.
- `buildSaveAllSql(bool $force = false): ?UpsertSql` — the SQL the upsert path would run (introspection/testing).
- `deleteAll(): int` — single `DELETE … WHERE pk IN (…)`.
- `load(string ...$relationPaths): static` — load relations onto the set (dot-paths; multiple paths share prefixes, loaded once). `loadMissing(...)` skips records already having the relation (a to-one that resolved null still counts as loaded). `with(...)` is a deprecated alias for `load()`.

`SaveResult` (readonly): `int $inserted`, `int $updated`, `list<int|string> $insertedIds`,
`total(): int`. `UpsertSql` (readonly): `string $create`, `string $lock`, `?string $update`.

---

## 9. Column casting

Map a column to a value object / JSON / custom type via a `#[Cast]`-family attribute. A caster
is authoritative: it owns both directions and native type handling is bypassed.

`ColumnCaster` interface:
- `fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed` — DB value (+ full row, for
  discriminated casters) → PHP value. Never called for a null raw value.
- `toDb(mixed $value, ColumnDefinition $col): int|float|string|null` — PHP value → bound scalar.

Built-in casters (all extend abstract `Cast implements ColumnCaster`, used as attributes):
- `#[DateTimeCaster(string $timezone = 'UTC')]` — column ↔ `\DateTimeImmutable`.
- `#[EpochCaster]` — integer epoch column ↔ `\DateTimeImmutable`.
- `#[JsonCaster(array|bool $excludeNullFields = false)]` — JSON column ↔ array / `JsonCastable`
  object. `excludeNullFields` drops null keys on write (whole payload `true`, or a field list).
- `#[EnumCaster(class-string<\BackedEnum> $enum)]` — scalar column ↔ a **backed enum** (property typed
  as the enum). Normalizes the raw scalar to the enum backing before `::from()`, so int- and
  string-backed enums both round-trip; a non-backed enum is rejected at construction. Use it to type a
  status/basis column as its enum (`public MyStatus $status`) instead of a scalar + hand-rolled `tryFrom`.
  On a `ColumnType::Enum` column, **omit `enumValues:` — the schema builder derives the `ENUM(...)`
  value list from the enum's cases** (the caster already names the enum; an inline list would just
  duplicate it and could drift). Provide `enumValues:` only to intentionally narrow the column to a
  subset of the enum's cases.

`JsonCastable` interface (for value objects stored as JSON): `static fromJson(array $data): static`.

Full detail + discriminated-payload pattern: [column-casting.md](column-casting.md).

---

## 10. Binary values (`BinaryParam`)

`Binary`/`VarBinary` columns carry raw bytes (e.g. an application-minted `BINARY(16)`/`BYTEA`
UUID PK). PostgreSQL rejects a raw byte string bound as a positional parameter (it is read as
UTF-8 text), so on PostgreSQL binary parameters are wrapped. The wrapping is **dialect-gated**
— it never activates on MySQL, so a MySQL consumer's custom `DbSession` that only accepts
scalars is unaffected:

- `final class BinaryParam implements \Stringable { public function __construct(public readonly string $bytes) {} }`
- `SqlDialect::bindsBinaryAsLob(): bool` — `false` for `MysqlDialect`, `true` for `PgsqlDialect`
  and `SqliteDialect` (SQLite binds as a LOB so bytes land in a `BLOB` column rather than being
  coerced to `TEXT` affinity).
- `ColumnSerializer::toParam(mixed $value, ColumnDefinition $col, bool $bindBinaryAsLob = false)`
  wraps binary values in `BinaryParam` **only when `$bindBinaryAsLob` is true**. The binding
  call sites (save/getOne/delete/upsert/update + RecordSet) pass `$dialect->bindsBinaryAsLob()`;
  the snapshot/export paths keep the default (raw byte string). So on MySQL `toParam` returns a
  plain string exactly as before.
- `PdoDbSession` binds a `BinaryParam` with `PDO::PARAM_LOB`; `MysqliDbSession`/`WpDbSession`
  unwrap to the raw byte string (defensive — they only receive one if used with a PG dialect).
- Reads: PG returns `bytea` as a stream resource; `ColumnSerializer::fromDb()` reads it to raw
  bytes. The dirty snapshot for binary columns is taken from the decoded value (the stream is
  single-read).

**When you must wrap manually:** an ad-hoc `WhereClause` predicate on a binary column has no
column metadata, so on PostgreSQL pass `new BinaryParam($bytes)` as the value. PK lookups
(`getOne`, `delete`) and column writes (`save`, `saveAll`) wrap for you. On MySQL, a plain byte
string works and wrapping is unnecessary (but harmless).

---

## 11. DDL generation, dialects, and sessions

### `SqlDialect` interface (per-dialect SQL)
`bindsBinaryAsLob(): bool` (false for MySQL, true for PG and SQLite — see [§10](#10-binary-values-binaryparam)),
`toLiteral(mixed, ColumnDefinition): string`, `quoteIdentifier(string): string`,
`escapeLikeWildcards(string): string`, `likeEscapeSuffix(): string`,
`insertReturningSuffix(string $quotedPkColumn): string`,
`forUpdateClause(): string`, `connectionInitStatements(): array` (`list<string>`),
`buildBulkInsert(...)`, `buildSingleUpsertSql(...)`,
`buildUpsertSql(string $tableName, string $pkColumn, array $columnNames, array $rows, array $updateColumns, array $rowDirtyColumns = []): UpsertSql`,
`buildCreateTable(TableSchema $schema, bool $ifNotExists = false): string`.

New in v0.2.0:
- `forUpdateClause(): string` — the row-locking suffix for a `SELECT … FOR UPDATE` read.
  Returns `'FOR UPDATE'` on `MysqlDialect` and `PgsqlDialect`; `''` on `SqliteDialect` (SQLite
  serializes writers at the database level — no per-row lock clause; callers append this after
  `ORDER BY`, so an empty string yields a plain ordered SELECT).
- `connectionInitStatements(): list<string>` — per-connection setup statements run by
  `Connection` on construction. `[]` for `MysqlDialect`/`PgsqlDialect`; for `SqliteDialect` the
  `PRAGMA journal_mode` / `busy_timeout` / `foreign_keys` statements (per its constructor).
- `buildUpsertSql()` gained a trailing `array $rowDirtyColumns = []` param
  (`list<array<string,bool>>`, per-row set of changed column names) driving per-row dirty
  scoping in the join-based UPDATE (see [below](#buildupsertsql--join-based-multi-mask-upsert-step-3)).

Implementations: `MysqlDialect` (constructor: `?string $defaultEngine = null`,
`?string $defaultCharset = null`, `?string $defaultCollation = null`), `PgsqlDialect` (no
constructor args), `SqliteDialect` (see below). All three `use UpsertJoinBuilder` (the shared
mask/derived-table trait).

### `SqliteDialect` (v0.2.0)
Third backend; requires **SQLite >= 3.33** (`UPDATE … FROM`) and 3.35+ for `RETURNING` id
back-fill. Constructor:
```php
new SqliteDialect(
    ?string $journalMode = 'WAL',    // PRAGMA journal_mode; null leaves the default
    int $busyTimeoutMs = 5000,       // PRAGMA busy_timeout (ms); typed int — no null
    bool $foreignKeys = true,        // PRAGMA foreign_keys=ON (SQLite defaults it OFF)
)
```
(`$busyTimeoutMs` is a plain `int`, not nullable; only `$journalMode` is nullable.)
`connectionInitStatements()` emits `PRAGMA journal_mode=<mode>` (when non-null),
`PRAGMA busy_timeout=<ms>`, and `PRAGMA foreign_keys=ON` (when `$foreignKeys`). See
[§13](#13-dialect-portability) for its DDL/type mappings.

### `buildCreateTable()` — DDL from attributes
```php
$sql = (new MysqlDialect())->buildCreateTable(TableSchema::fromClass(Order::class), ifNotExists: true);
$sql = (new PgsqlDialect())->buildCreateTable(TableSchema::fromClass(Order::class));
```
MySQL returns a single statement (indexes/comments inline). PostgreSQL returns a
**semicolon-separated batch** (`CREATE TABLE` + trailing `CREATE INDEX` / `COMMENT ON`),
runnable in one `PDO::exec()`. SQLite likewise returns a semicolon-separated batch (`CREATE TABLE`
+ trailing `CREATE INDEX`; no comments), with a single auto-increment PK declared inline as
`INTEGER PRIMARY KEY AUTOINCREMENT` (and the separate `PRIMARY KEY (...)` clause then omitted).
Full mapping + rejections: [ddl-generation.md](ddl-generation.md).

### `buildUpsertSql()` — join-based multi-mask upsert (step 3)
As of v0.2.0 step 3 (the `UPDATE`) is a **derived-table join with per-row integer masks**, not a
per-column `CASE pk WHEN …`. Shared logic lives in the `Dialect\UpsertJoinBuilder` trait
(`computeUpsertMaskPlan()`, `renderUpsertDerivedTable()`, `buildUpsertDerivedColumns()`), used by
all three dialects.

Given `$rows` + `$updateColumns` + `$rowDirtyColumns`, it builds
`UPDATE … JOIN (SELECT … UNION ALL SELECT …) u` (MySQL) / `UPDATE … FROM (…) u` (PG, SQLite),
where the derived table `u` carries the PK, per-row mask column(s) `_m0, _m1, …`, and the update
columns. Column classification:
- **Uniform** — changed by *every* row (or no dirty info at all, i.e. `$rowDirtyColumns === []`):
  written directly (`SET col = u.col`), no mask bit.
- **Sparse** — changed by only some rows: gated by a mask bit so rows that did not change it keep
  their live value (`ELSE` the table's own column).

Each mask integer holds **63 usable bits** (bit 63 is the sign bit of a 64-bit signed integer);
`> 63` sparse columns spill into additional mask columns `_m1, _m2, …`. Complexity O(N·M) (N rows
× M update columns). Per-dialect SET expression for a sparse column with bit `b` in mask `_mk`
(`k = ⌊b/63⌋`, `bit = 1 << (b % 63)`):

| Dialect | Sparse-column SET expression |
|---|---|
| MySQL | `` `t`.`col` = IF(u.`_m0` & bit, u.`col`, `t`.`col`) `` |
| PostgreSQL | `"col" = CASE WHEN (u."_m0" & bit) <> 0 THEN u."col" ELSE "t"."col" END` |
| SQLite | `"col" = iif(u."_m0" & bit, u."col", "t"."col")` |

(`u` is the derived table's alias; `t` above stands for the **actual quoted table name** — the
ELSE branch is qualified with the real table, not an alias. A uniform column collapses to
`SET col = u.col`.)

The derived table is rendered as `SELECT <lit> AS <col>, … UNION ALL SELECT <lit>, …` (first
branch aliases the columns, later branches match by position) — identical across all three
dialects, no per-dialect `VALUES` typing. Steps 1 (`create`) and 2 (`lock`) are unchanged; step 3
is `null` when `$updateColumns` is empty.

### `DbSession` interface (one connection abstraction)
`exec(string $sql, array $params = []): int`,
`fetchAll(...)`, `fetchOne(...): ?array`, `fetchScalar(...): string|int|float|null`,
`lastInsertId(): string|int`, `transactional(\Closure): mixed`,
`withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed`,
`inTransaction(): bool`, `isDuplicateKeyError(\Throwable): bool`,
`isRetryableTransactionError(\Throwable $throwable): bool`.
Param type is `array<array-key, scalar|BinaryParam|null>`; both `?` and `:named` placeholders
are accepted (`NamedPlaceholderSql` normalizes named → positional).

`isRetryableTransactionError(\Throwable): bool` (v0.2.0) — whether a throwable is a **transient,
retryable** transaction conflict (deadlock, serialization failure, lock-wait timeout,
`SQLITE_BUSY`) as opposed to a permanent failure (constraint violation, syntax error). Companion
to `isDuplicateKeyError()`; the default classification **includes deadlocks** (most apps want them
retried). Consumed by `RetryingDbSession`. Per-implementation classification:

| Session | Classified as retryable |
|---|---|
| `PdoDbSession` | only `\PDOException`; switches on the PDO driver: **pgsql** SQLSTATE `40001` (serialization_failure) / `40P01` (deadlock_detected); **sqlite** driver code `5` (`SQLITE_BUSY`) / `6` (`SQLITE_LOCKED`) or message contains `locked`; **mysql/other** driver errno `1213` (deadlock) / `1205` (lock-wait timeout) / `1020` (MariaDB MVCC re-read) |
| `MysqliDbSession` | `getCode()` **or** `$conn->errno` in `1213` / `1205` / `1020` |
| `WpDbSession` | message or `wpdb->last_error` contains `Deadlock found` / `Lock wait timeout exceeded` / `Record has changed since last read` |
| `CapturingDbSession` | always `false` (test stub) |

Implementations:
- `PdoDbSession(\PDO $pdo)` — recommended; works for MySQL, MariaDB, PostgreSQL, SQLite.
  Auto-detects the driver for advisory locks and binds `BinaryParam` as a LOB.
- `MysqliDbSession(\mysqli $conn)` — MySQL/MariaDB.
- `WpDbSession(\wpdb $wpdb)` — WordPress; converts `?` to `%s`.
- `RetryingDbSession(...)` — DbSession decorator; retries the outer transaction (see below).
- `CapturingDbSession` (in `Test\`) — records SQL+params without a DB, for unit assertions.

### `RetryingDbSession` (v0.2.0)
A `DbSession` **decorator** that retries the **outermost** transaction on classified transient
conflicts with exponential backoff + jitter. Opt-in — wrap a session with it only where retries
are wanted:
```php
$conn = new Connection(new RetryingDbSession(new PdoDbSession($pdo)), new PgsqlDialect());
```
Constructor:
```php
public function __construct(
    private readonly DbSession $inner,
    private readonly int $maxAttempts = 10,   // total attempts including the first (>= 1)
    private readonly int $baseDelayUs = 5_000, // base backoff µs, doubled per attempt
    private readonly int $maxDelayUs = 100_000, // per-attempt backoff cap µs
    ?\Closure $retryable = null,               // (\Closure(\Throwable): bool)|null — overrides classification
)
```
Semantics:
- **Only the outermost transaction is retried.** If `$inner->inTransaction()` is already true, the
  call runs inline (no retry loop) — nested `transactional()` calls join the outer one.
- **Which errors retry:** `$retryable` if given, else `$inner->isRetryableTransactionError()`. (A
  consumer with strict lock-order discipline can pass a predicate that returns `false` for
  deadlocks to surface them instead.)
- **Backoff:** `min($maxDelayUs, $baseDelayUs * 2**(attempt-1))` plus up-to-50% jitter, via
  `usleep()`.
- **Idempotency contract:** the closure passed to `transactional()` is **re-run on every attempt**.
  Any effect the DB does not roll back (HTTP calls, queue publishes, file writes, in-memory
  mutation) will repeat — closures must be safe to re-run (pure-SQL / side-effect-free outside the
  DB).
- Every other `DbSession` method (`exec`, `fetchAll`, `fetchOne`, `fetchScalar`, `lastInsertId`,
  `withAdvisoryLock`, `inTransaction`, `isDuplicateKeyError`, `isRetryableTransactionError`)
  **delegates verbatim** to `$inner`. So `Record::transactional()` and `RecordSet::saveAll()`
  (which funnel through `transactional()`) gain retries automatically.

---

## 12. Exceptions

All under `Nandan108\Attrecord\Exception`, extending `AttrecordException` (which extends
`\RuntimeException`) unless noted.

| Exception | Thrown when |
|---|---|
| `AttrecordException` | base; generic library errors (e.g. bad named param). |
| `RecordNotFoundException` | `getOneOrFail()` finds nothing. |
| `RecordValidationException` | `validate()` rejects the record. |
| `RecordSaveException` | INSERT/UPDATE fails (wraps the driver error). |
| `RecordDeleteException` | DELETE fails / no PK. |
| `SchemaException` | invalid attribute metadata at schema build / DDL (missing length, decimal scale, enum values, PG `Set`/`Virtual`, …). |
| `MissingLockTierException` | `LockSet::acquire()` target lacks `#[LockTier]`. |
| `LockTierConflictException` | two `LockSet` targets share a tier. |
| `LockAssertionException` | `Transaction::assertLocked()` fails. |
| `TransactionException` | transaction-state misuse. |

---

## 13. Dialect portability

| Concern | MySQL / MariaDB | PostgreSQL | SQLite (>= 3.33) |
|---|---|---|---|
| Identifier quoting | `` `backtick` `` | `"double-quote"` | `"double-quote"` |
| Auto-increment PK | `BIGINT UNSIGNED AUTO_INCREMENT` + `lastInsertId()` | `BIGSERIAL` + `RETURNING` | `INTEGER PRIMARY KEY AUTOINCREMENT` (inline, no separate PK clause) + `RETURNING` |
| Unsigned integers | native | none — widened | none — `INTEGER` affinity |
| Bulk upsert (3-step) | `INSERT IGNORE` + `SELECT … FOR UPDATE` + join `UPDATE` | `INSERT … ON CONFLICT DO NOTHING` + `SELECT … FOR UPDATE` + `UPDATE … FROM` join | `INSERT OR IGNORE` + ordered `SELECT` (no `FOR UPDATE`) + `UPDATE … FROM` join |
| Single upsert | `ON DUPLICATE KEY UPDATE col = VALUES(col)` | `ON CONFLICT (cols) DO UPDATE SET col = EXCLUDED.col` | `ON CONFLICT (cols) DO UPDATE SET col = excluded.col` |
| Sparse-column join mask op | `IF(mask & bit, u.col, t.col)` | `CASE WHEN (mask & bit) <> 0 THEN u.col ELSE t.col END` | `iif(mask & bit, u.col, t.col)` |
| `forUpdateClause()` | `FOR UPDATE` | `FOR UPDATE` | `''` (writers serialized at DB level) |
| `connectionInitStatements()` | `[]` | `[]` | `PRAGMA journal_mode` / `busy_timeout` / `foreign_keys` |
| Binary param bind | string | `PDO::PARAM_LOB` (`bytea`); read back from stream | `PDO::PARAM_LOB` (`BLOB`); `X'hex'` literal |
| Advisory locks | `GET_LOCK`/`RELEASE_LOCK` | `pg_advisory_lock` (crc32-hashed key); timeout polled via `pg_try_advisory_lock` | none (no `GET_LOCK` primitive) |
| Duplicate-key SQLSTATE (`isDuplicateKeyError`) | `23000` | `23505` | `23000` |
| Retryable-error signal (`isRetryableTransactionError`) | errno `1213`/`1205`/`1020` | SQLSTATE `40001`/`40P01` | code `5`(`SQLITE_BUSY`)/`6`(`SQLITE_LOCKED`) or msg `locked` |
| `LIKE` escape | implicit backslash | explicit `ESCAPE '\'` (handled by `WhereClause`) | explicit `ESCAPE '\'` |
| DDL `Enum` | `ENUM(...)` | `TEXT` + `CHECK (... IN (...))` | `TEXT` + `CHECK (... IN (...))` |
| DDL indexes/comments | inline | trailing `CREATE INDEX` / `COMMENT ON` | trailing `CREATE INDEX`; comments dropped (no support) |
| DDL `Set` | supported | `SchemaException` | `SchemaException` |
| DDL `VIRTUAL` generated | supported (also `STORED`) | `SchemaException` (`STORED` only) | supported (`STORED` + `VIRTUAL`, 3.31+) |
| `onUpdate` / engine/charset | emitted | omitted (no equivalent) | omitted (no equivalent) |
| `whereInTuples` (row-value IN) | supported | supported | supported (SQLite ≥ 3.15) |

`generatedAs` expressions are raw SQL → dialect-specific. Prefer portable functions
(`COALESCE` over `IFNULL`) when a Record's DDL must build on multiple engines.

---

## 14. Locking

- `#[LockTier(int $tier)]` on a Record assigns its lock-ordering tier (ascending).
- `LockSet::acquire(DbSession $session, array<class-string,list<int|string>> $targets, ?Transaction $tx = null): array`
  — locks rows across multiple Record classes in **tier order**, ascending-PK within each
  tier, to prevent deadlocks. Throws `MissingLockTierException` / `LockTierConflictException`.
- `Transaction` — tracks acquired locks for assertions: `current()`, `push()`, `pop()`,
  `registerLock(Record)`, `assertLocked(Record)`.
- `find(..., forUpdate: true)` / `getOne(..., forUpdate: true)` append the dialect's
  `forUpdateClause()` (`FOR UPDATE` on MySQL/PG; `''` on SQLite → a plain ascending-PK ordered
  SELECT, since SQLite serializes writers at the DB level) and (with a `$tx`) register the locks.
- Advisory locks via `DbSession::withAdvisoryLock()` — connection-scoped named mutexes,
  portable across MySQL and PostgreSQL (SQLite has no such primitive — see
  [§13](#13-dialect-portability)).
- `RetryingDbSession` retries the outermost transaction on transient conflicts (deadlock /
  serialization failure / lock-wait timeout / `SQLITE_BUSY`) — see [§11](#retryingdbsession-v020).

---

## 15. WhereClause

Immutable builder; `render(?SqlDialect)` produces dialect-correct SQL, `params()` the bound
values. Static constructors: `where`, `match` (AND-ed all-equal from a `array<string, scalar|null>`
map — raw scalars, match enum/VO columns by their `->value`), `whereIn`, `whereNotIn`, `whereInTuples`,
`whereNotInTuples`, `whereRaw`, `whereLike`, `whereNotLike`, `whereNot`, `whereBetween`,
`whereNotBetween`, `whereNone`, `whereAll`, `whereAny`. Instance combinators: `andWhere(...)`,
`orWhere(...)`. Values accept `scalar|null` and `BinaryParam`; a bound `bool` is normalized to its
SQL scalar form (`true`→`1`, `false`→`0`) at the `params()` boundary, consistent across drivers.
Pattern values for `LIKE` should be pre-escaped with `$dialect->escapeLikeWildcards()`. Full grammar:
[where-clause.md](where-clause.md). `RawSql(string $expression, array $params = [])` carries a
raw fragment with optional bound params for the WHERE/SET escape hatch.

---

## 16. Invariants & gotchas

- **`save()` writes only dirty columns.** A clean `save()` is a no-op (`->_saved === false`)
  unless `force: true`.
- **Never loop DB calls.** Use `saveAll()` / `deleteAll()` / `load()` / `whereIn()` — each is a
  single statement. Repository-style methods should be plural by default.
- **Generated columns are never written** (INSERT or UPDATE) — every engine rejects a value for a
  `GENERATED ALWAYS` column.
- **Auto-increment PKs are back-filled as `int`** on all engines (PG/SQLite via `RETURNING`,
  MySQL via `lastInsertId()` + sequential range; bigint strings are cast through the serializer).
- **Binary on PostgreSQL** needs `BinaryParam` only for ad-hoc `WhereClause` predicates; PK and
  column paths wrap automatically.
- **`__()`/textdomain note** is a *consumer* (WordPress) concern, not attrecord's — attrecord
  has no i18n surface.
- **No schema diffing / migrations.** `buildCreateTable()` is fresh-install DDL only.
