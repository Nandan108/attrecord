# Column casting — value objects, JSON & custom mappings

By default, attrecord maps each column to a native PHP type from its `ColumnType`
(`int`, `bool`, `float`, `string`, `DateTimeImmutable`, decimal-as-string). **Column
casting** lets a `#[Column]` property instead hold a richer value — a value object, a
typed array, a decoded JSON payload — while attrecord transparently serializes it to a
DB scalar on write and reconstructs it on read.

Casting is the **opt-in extension layer**: native types keep their built-in mapping
(it's the hot path and already correct); a cast only kicks in where you ask for one,
or where the native default would be wrong (an `array`-typed `Json` column).

```php
#[Column(ColumnType::Json, nullable: true)]
public ?array $meta = null;                 // auto-cast: array ⇄ JSON text

#[Column(ColumnType::Json, nullable: true)]
public ?Money $price = null;                // auto-cast: VO ⇄ JSON (Money implements JsonCastable)

#[Column(ColumnType::IntUnsigned)]
#[EpochCaster]
public ?\DateTimeImmutable $logged_at = null; // DateTimeImmutable ⇄ unix int
```

---

## The `#[Cast]` family — the attribute *is* the caster

A caster is declared as a PHP attribute on the column property. Rather than a
class-string holder, **the caster attribute itself carries the behavior**: the abstract
`Cast` attribute implements the `ColumnCaster` contract, and concrete casters extend it.
This makes casters self-declaring and parameterizable — the attribute's own constructor
arguments configure it.

```php
use Nandan108\Attrecord\Caster\JsonCaster;

#[Column(ColumnType::Json, nullable: true)]
#[JsonCaster]                               // default config
public ?array $meta = null;

#[Column(ColumnType::Json, nullable: true)]
#[JsonCaster(excludeNullFields: ['note'])]  // parameterized
public ?array $audit = null;
```

`#[Cast]` is valid on **any non-generated column** — including temporal and integer
columns. When a caster is present it is **authoritative** for that column: its output is
the DB scalar (and its input the reconstructed value), bypassing the native type
mapping. The only columns that reject a caster are `autoIncrement` and generated columns
(their values aren't application-written).

At most one caster attribute per property; a second one is a schema error.

---

## Built-in casters

All built-ins live under `Nandan108\Attrecord\Caster\`, are **not** `final` (extend them
for custom encoding), and each declares its own `#[\Attribute(\Attribute::TARGET_PROPERTY)]`
marker.

### `JsonCaster` — array / value-object ⇄ JSON

Encodes via `json_encode()` (with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`) and
decodes via `json_decode(..., true)`. Used for plain arrays and for `JsonCastable` value
objects (see next section).

`excludeNullFields` controls null pruning on write:

```php
#[JsonCaster]                               // keep all keys
#[JsonCaster(true)]                         // drop every null-valued top-level key
#[JsonCaster(excludeNullFields: ['note'])]  // drop only the named keys when null
```

`excludeNullFields` applies to plain-array payloads only; a value object owns its own
shape via `jsonSerialize()`.

### `DateTimeCaster` — timezone-normalized datetime

Opt-in (never auto-attached — plain `DateTime`/`Date` columns use the native mapping).
Normalizes through a configurable storage timezone:

```php
#[Column(ColumnType::DateTime, precision: 6)]
#[DateTimeCaster('UTC')]                     // or e.g. 'Europe/Zurich'
public ?\DateTimeImmutable $occurred_at = null;
```

### `EpochCaster` — datetime ⇄ unix integer

For a timestamp stored as an integer column:

```php
#[Column(ColumnType::BigIntUnsigned, nullable: true)]
#[EpochCaster]
public ?\DateTimeImmutable $logged_at = null;
```

---

## JSON columns and `JsonCastable` value objects

A `ColumnType::Json` column gets `JsonCaster` **auto-attached** (no `#[Cast]` needed)
when its property is typed as `array`/`?array`, or as a class implementing
`Nandan108\Attrecord\JsonCastable`:

```php
#[Column(ColumnType::Json, nullable: true)]
public ?array $meta = null;        // array ⇄ JSON

#[Column(ColumnType::Json, nullable: true)]
public ?Money $price = null;       // Money ⇄ JSON, via JsonCastable
```

A `JsonCastable` value object plugs into the standard library: encoding reuses
`\JsonSerializable`, decoding adds one named constructor.

```php
interface JsonCastable extends \JsonSerializable
{
    /** @param array<array-key, mixed> $data decoded JSON payload */
    public static function fromJson(array $data): static;
}
```

```php
final class Money implements JsonCastable
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public static function fromJson(array $data): static
    {
        return new self((int) $data['amount'], (string) $data['currency']);
    }
}
```

- **Encode** uses the inherited `jsonSerialize()` (which `json_encode()` honors), so the
  VO serializes itself.
- **Decode** runs `json_decode(..., true)` and hands the array to `fromJson()`.

`fromJson()` receives the decoded **array**, not the raw string — symmetric with
`jsonSerialize()`, so the VO never re-does JSON mechanics. This covers the case where the
column always holds one VO class, or a self-describing payload whose discriminator lives
inside the JSON (`fromJson()` can dispatch on `$data[...]`). A payload whose shape
depends on a **sibling column** needs a custom caster — see below.

> A string-typed `Json` column (`public ?string $raw`) is left untouched: no caster, raw
> JSON passthrough. Casting only activates for `array` or `JsonCastable` properties.

---

## Writing a custom caster

Extend `Cast` (which gives you the `ColumnCaster` contract) and declare the attribute
marker. The contract is two methods:

```php
namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Schema\ColumnDefinition;

interface ColumnCaster
{
    /**
     * Raw DB scalar → rich PHP value. $row is the full raw row being hydrated, so a
     * caster can read a sibling discriminator column to pick the target type.
     * Never called with $raw === null.
     *
     * @param scalar                     $raw
     * @param array<string, scalar|null> $row
     */
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed;

    /** Rich PHP value → DB scalar. Never called with $value === null. */
    public function toDb(mixed $value, ColumnDefinition $col): int|float|string|null;
}
```

`null` is handled by the framework on both sides — a caster never sees a null value.
Casters must be **stateless**: one instance is created per property at schema-build time
(it's the attribute instance) and reused across every row, so per-instance config via
readonly constructor args is fine, per-row mutable state is not.

A **discriminated** payload reads its sibling column from `$row`:

```php
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EventPayloadCaster extends Cast
{
    public function fromDb(mixed $raw, array $row, ColumnDefinition $col): mixed
    {
        $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        return match ($row['event_type'] ?? null) {
            'refund'   => RefundPayload::fromJson($data),
            'shipment' => ShipmentPayload::fromJson($data),
            default    => $data,
        };
    }

    public function toDb(mixed $value, ColumnDefinition $col): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
```

```php
#[Column(ColumnType::Json, nullable: true)]
#[EventPayloadCaster]
public RefundPayload|ShipmentPayload|null $payload = null;
```

> Each concrete caster **must** declare its own
> `#[\Attribute(\Attribute::TARGET_PROPERTY)]` — PHP does not honor an attribute marker
> inherited from a parent class. A subclass missing it raises *"Attempting to use
> non-attribute class … as attribute"* when the schema is built.

`fromDb` takes `$row` so the read side can discriminate; `toDb` does not, since the write
side already has a fully-formed value that knows how to serialize itself.

---

## Interaction with dirty tracking & bulk save

Casting flows through the same single serialization funnel as every other write path
(`save`, `updateWhere`, the unique-key/where update helpers, and bulk `saveAll`), so it
works uniformly with the rest of attrecord:

- **Dirty tracking just works**, including for mutable value objects. Because attrecord
  detects changes by serialize-and-compare (not object identity), mutating a VO and
  re-saving correctly writes the change. A freshly loaded, untouched record is never
  falsely dirty — even for native `JSON` columns, whose stored bytes the database
  normalizes (key order, whitespace): the snapshot is taken from attrecord's own
  canonical encoding on both sides.
- **Bulk `saveAll()`** applies casters on its literal-building path too, so casted
  columns serialize identically whether saved individually or in a batch.

---

## Auto-attach rules

`JsonCaster` is attached automatically when **all** of these hold, and never overrides an
explicit `#[Cast]`:

- the column is `ColumnType::Json`, **and**
- the property type is `array`/`?array`, or a `JsonCastable` class, **and**
- no caster attribute is already present.

Native scalar and temporal types are **not** auto-cast — their built-in mapping is
already correct and lives on the hottest code path. Casting them is available on demand
via `#[Cast]` (e.g. `EpochCaster` on an int column) but never imposed.

The auto-attach is backward-compatible: a `?string` `Json` column keeps returning raw
JSON. The rule only activates a property already typed `array` (whose native default
would otherwise stringify to `"Array"`) or one explicitly typed as a value object.

---

## Casting and DDL

Casters have **no effect on generated DDL**. A casted column's SQL type is exactly what
its `#[Column]` declares — a value object stored in a `ColumnType::Json` column still
emits `JSON`. Casting is purely a runtime value mapping.

---

## Limitations

- **No runtime-dependency casters.** Parameterization via constant attribute arguments is
  supported (e.g. `#[DateTimeCaster('UTC')]`), but a caster cannot receive a runtime
  service (a DB handle, a container binding) — attribute arguments must be constant.
  attrecord has no container and stays dependency-free; such a caster reads from its own
  domain state instead.
- **`NULL` maps to `null`.** A caster is never invoked for a null value; mapping SQL
  `NULL` to a non-null object is not supported.
- Casters cannot be applied to relation, primary-key, `autoIncrement`, or generated
  columns.
