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
- **Two supported SQL dialects: MySQL/MariaDB and PostgreSQL.** A `SqlDialect` abstraction
  isolates the differences; the same Record code runs on both. SQLite partially works through
  PDO but is not a first-class target. See [§13 Dialect portability](#13-dialect-portability).
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
| `Nandan108\Attrecord\Caster` | `DateTimeCaster`, `EpochCaster`, `JsonCaster` |
| `Nandan108\Attrecord\Dialect` | `MysqlDialect`, `PgsqlDialect` |
| `Nandan108\Attrecord\Session` | `PdoDbSession`, `MysqliDbSession`, `WpDbSession` |
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

- `Connection` is a final readonly value object: `public readonly DbSession $session`,
  `public readonly SqlDialect $dialect`.
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

| PHP type | `ColumnType` cases | MySQL SQL | PostgreSQL SQL |
|---|---|---|---|
| `int` | `TinyInt`,`SmallInt`,`MediumInt`,`Int`,`BigInt` (+`*Unsigned`), `Year`, `Bit` | as named (+`UNSIGNED`) | `SMALLINT`/`INTEGER`/`BIGINT` (no unsigned); `BIT(n)` |
| `bool` | `Bool` | `TINYINT(1)` | `BOOLEAN` |
| `float` (or exact `string`) | `Float`,`Double`,`Decimal` | `FLOAT`/`DOUBLE`/`DECIMAL(p,s)` | `REAL`/`DOUBLE PRECISION`/`NUMERIC(p,s)` |
| `string` | `Char`,`VarChar`,`TinyText`,`Text`,`MediumText`,`LongText`,`Json`,`Enum`,`Set`,`Binary`,`VarBinary` | as named | `CHAR(n)`/`VARCHAR(n)`/`TEXT`/`JSONB`/`TEXT`+CHECK/(Set→error)/`BYTEA` |
| `\DateTimeImmutable` | `Date`,`DateTime`,`Timestamp` | `DATE`/`DATETIME(p)`/`TIMESTAMP(p)` | `DATE`/`TIMESTAMP(p)` |

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
`MorphOne`, `MorphTo`.

| Type | FK location | PHP property type | Required params |
|---|---|---|---|
| `OneToMany` | related table | `?RecordSet<T>` | `class`, `foreignKey` |
| `ManyToOne` | this table | `?T` | `class`, `foreignKey` |
| `OneToOne` | this table | `?T` | `class`, `foreignKey` |
| `OneToOneReversed` | related table | `?T` | `class`, `foreignKey` |
| `MorphMany` | related table | `?RecordSet<T>` | `class`, `morphType`, `morphKey`, `morphValue` |
| `MorphOne` | related table | `?T` | `class`, `morphType`, `morphKey`, `morphValue` |
| `MorphTo` | this table | union `?T` | `morphType`, `morphKey`, `morphMap` |

Loaded eagerly via `RecordSet::with('relation')` or dot-paths `with('posts.user')`,
`with('tags.tagable')`. Eager loading is batched (one `IN(…)` query per relation level — no
N+1). See [polymorphic-relations.md](polymorphic-relations.md).

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
- `whereInTuples(array $columns, array $rows): RecordSet<static>` — row-value-constructor IN (MySQL+PG; not SQLite).
- `countWhere(string|WhereClause $where, array $params = []): int`
- `updateWhere(array $set, string|WhereClause $where = '', array $params = []): int` — bulk UPDATE.
- `deleteWhere(string|WhereClause $where, array $params = []): int` — bulk DELETE.

Construction / mutation:
- `newWith(array $attrs): static` — construct + `set()`.
- `set(array $attrs, bool $validate = true): static` — assign + optional `validate()`.
- `validate(): void` — override to enforce invariants (throw `RecordValidationException`).
  Called from `set()` and at save time.
- `beforeSave(): void` — override hook; runs before INSERT/UPDATE (e.g. stamp timestamps).

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
- `saveAll(bool $force = false): ?SaveResult` — one statement: plain bulk `INSERT` for PK-null
  records; deadlock-safe 3-step upsert for keyed records (INSERT-IGNORE/ON-CONFLICT-DO-NOTHING →
  `SELECT … FOR UPDATE` ascending-PK → CASE `UPDATE`). Back-fills auto-increment PKs onto new
  records (PG via `RETURNING`, MySQL via `lastInsertId()` + sequential range). Returns `null` if
  nothing was dirty.
- `upsertAllByUniqueKey(string $conflictKey): ?SaveResult` — bulk burn-free upsert by unique key.
- `buildSaveAllSql(bool $force = false): ?UpsertSql` — the SQL the upsert path would run (introspection/testing).
- `deleteAll(): int` — single `DELETE … WHERE pk IN (…)`.
- `with(string $relationPath): static` — eager-load a relation (dot-paths supported).

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
- `SqlDialect::bindsBinaryAsLob(): bool` — `false` for `MysqlDialect`, `true` for `PgsqlDialect`.
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
`bindsBinaryAsLob(): bool` (false for MySQL, true for PG — see [§10](#10-binary-values-binaryparam)),
`toLiteral(mixed, ColumnDefinition): string`, `quoteIdentifier(string): string`,
`escapeLikeWildcards(string): string`, `likeEscapeSuffix(): string`,
`insertReturningSuffix(string $quotedPk): string`,
`buildBulkInsert(...)`, `buildSingleUpsertSql(...)`, `buildUpsertSql(...): UpsertSql`,
`buildCreateTable(TableSchema $schema, bool $ifNotExists = false): string`.

Implementations: `MysqlDialect` (constructor: `defaultEngine`, `defaultCharset`,
`defaultCollation`), `PgsqlDialect`.

### `buildCreateTable()` — DDL from attributes
```php
$sql = (new MysqlDialect())->buildCreateTable(TableSchema::fromClass(Order::class), ifNotExists: true);
$sql = (new PgsqlDialect())->buildCreateTable(TableSchema::fromClass(Order::class));
```
MySQL returns a single statement (indexes/comments inline). PostgreSQL returns a
**semicolon-separated batch** (`CREATE TABLE` + trailing `CREATE INDEX` / `COMMENT ON`),
runnable in one `PDO::exec()`. Full mapping + rejections: [ddl-generation.md](ddl-generation.md).

### `DbSession` interface (one connection abstraction)
`exec(string $sql, array $params = []): int`,
`fetchAll(...)`, `fetchOne(...): ?array`, `fetchScalar(...): string|int|float|null`,
`lastInsertId(): string|int`, `transactional(\Closure): mixed`,
`withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed`,
`inTransaction(): bool`, `isDuplicateKeyError(\Throwable): bool`.
Param type is `array<array-key, scalar|BinaryParam|null>`; both `?` and `:named` placeholders
are accepted (`NamedPlaceholderSql` normalizes named → positional).

Implementations:
- `PdoDbSession(\PDO $pdo)` — recommended; works for MySQL, MariaDB, PostgreSQL, SQLite.
  Auto-detects the driver for advisory locks and binds `BinaryParam` as a LOB.
- `MysqliDbSession(\mysqli $conn)` — MySQL/MariaDB.
- `WpDbSession(\wpdb $wpdb)` — WordPress; converts `?` to `%s`.
- `CapturingDbSession` (in `Test\`) — records SQL+params without a DB, for unit assertions.

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

| Concern | MySQL / MariaDB | PostgreSQL |
|---|---|---|
| Identifier quoting | `` `backtick` `` | `"double-quote"` |
| Auto-increment PK | `BIGINT UNSIGNED AUTO_INCREMENT` + `lastInsertId()` | `BIGSERIAL` + `RETURNING` |
| Unsigned integers | native | none — widened |
| Bulk upsert | `INSERT IGNORE` + `FOR UPDATE` + CASE `UPDATE` | `ON CONFLICT DO NOTHING` + `FOR UPDATE` + CASE `UPDATE` |
| Single upsert | `ON DUPLICATE KEY UPDATE` | `ON CONFLICT (cols) DO UPDATE` |
| Binary param bind | string | `PDO::PARAM_LOB` (`bytea`); read back from stream |
| Advisory locks | `GET_LOCK`/`RELEASE_LOCK` | `pg_advisory_lock` (crc32-hashed key); timeout polled via `pg_try_advisory_lock` |
| Duplicate-key SQLSTATE | `23000` | `23505` |
| `LIKE` escape | implicit backslash | explicit `ESCAPE '\'` (handled by `WhereClause`) |
| DDL `Enum` | `ENUM(...)` | `TEXT` + `CHECK (... IN (...))` |
| DDL indexes/comments | inline | trailing `CREATE INDEX` / `COMMENT ON` |
| DDL `Set` / `VIRTUAL` generated | supported | `SchemaException` |
| `onUpdate` / engine/charset | emitted | omitted (no equivalent) |

`generatedAs` expressions are raw SQL → dialect-specific. Prefer portable functions
(`COALESCE` over `IFNULL`) when a Record's DDL must build on both engines.

---

## 14. Locking

- `#[LockTier(int $tier)]` on a Record assigns its lock-ordering tier (ascending).
- `LockSet::acquire(DbSession $session, array<class-string,list<int|string>> $targets, ?Transaction $tx = null): array`
  — locks rows across multiple Record classes in **tier order**, ascending-PK within each
  tier, to prevent deadlocks. Throws `MissingLockTierException` / `LockTierConflictException`.
- `Transaction` — tracks acquired locks for assertions: `current()`, `push()`, `pop()`,
  `registerLock(Record)`, `assertLocked(Record)`.
- `find(..., forUpdate: true)` / `getOne(..., forUpdate: true)` issue `SELECT … FOR UPDATE` in
  ascending-PK order and (with a `$tx`) register the locks.
- Advisory locks via `DbSession::withAdvisoryLock()` — connection-scoped named mutexes,
  portable across MySQL and PostgreSQL (see [§13](#13-dialect-portability)).

---

## 15. WhereClause

Immutable builder; `render(?SqlDialect)` produces dialect-correct SQL, `params()` the bound
values. Static constructors: `where`, `whereIn`, `whereNotIn`, `whereInTuples`,
`whereNotInTuples`, `whereRaw`, `whereLike`, `whereNotLike`, `whereNot`, `whereBetween`,
`whereNotBetween`, `whereNone`, `whereAll`, `whereAny`. Instance combinators: `andWhere(...)`,
`orWhere(...)`. Values accept `scalar|null` and `BinaryParam`. Pattern values for `LIKE` should
be pre-escaped with `$dialect->escapeLikeWildcards()`. Full grammar:
[where-clause.md](where-clause.md). `RawSql(string $expression, array $params = [])` carries a
raw fragment with optional bound params for the WHERE/SET escape hatch.

---

## 16. Invariants & gotchas

- **`save()` writes only dirty columns.** A clean `save()` is a no-op (`->_saved === false`)
  unless `force: true`.
- **Never loop DB calls.** Use `saveAll()` / `deleteAll()` / `with()` / `whereIn()` — each is a
  single statement. Repository-style methods should be plural by default.
- **Generated columns are never written** (INSERT or UPDATE) — both engines reject a value for a
  `GENERATED ALWAYS` column.
- **Auto-increment PKs are back-filled as `int`** on both engines (PG `RETURNING`/bigint strings
  are cast through the serializer).
- **Binary on PostgreSQL** needs `BinaryParam` only for ad-hoc `WhereClause` predicates; PK and
  column paths wrap automatically.
- **`__()`/textdomain note** is a *consumer* (WordPress) concern, not attrecord's — attrecord
  has no i18n surface.
- **No schema diffing / migrations.** `buildCreateTable()` is fresh-install DDL only.
