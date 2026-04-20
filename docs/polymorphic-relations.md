# Polymorphic relations — schema design

## When to use

Polymorphic relations are the right tool for **cross-cutting concerns** that genuinely
apply to many entity types: audit logs, tags, comments, attachments, notifications. A
single `movements` or `audit_entries` table referencing heterogeneous sources is cleaner
than a combinatorial explosion of nullable FKs or duplicated tables.

## Trade-offs

| Concern                                 | Impact                                                                                                                                            |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| No referential integrity                | The database cannot enforce a FK on `(tagable_type, tagable_id)`. Orphaned rows accumulate silently unless you clean them up in application code. |
| String discriminators couple DB to code | Renaming a class or changing a type string silently breaks all existing rows. Prefer stable short keys (`'order'`, `'product'`) over class names. |
| Index overhead                          | A composite index on `(tagable_type, tagable_id)` is required for acceptable query performance; it will not be created automatically.             |

## Prefer integer discriminators for high-volume tables

Instead of storing `'order'` as a varchar, store the FK of a `movement_types` lookup
table. This is faster to index, smaller on disk, and decouples the DB entirely from PHP
class names:

```
movement_types
  id    tinyint PK
  name  varchar   ← 'order', 'transfer', 'adjustment', …

movements
  id                 bigint PK
  movement_type_id   tinyint FK → movement_types.id   ← integer discriminator
  movement_ref_id    bigint                            ← polymorphic FK
  …
```

```php
#[Relation(RelationType::MorphTo,
    morphType: 'movement_type_id',
    morphKey:  'movement_ref_id',
    morphMap:  [1 => PurchaseOrder::class, 2 => Transfer::class, 3 => Adjustment::class])]
public PurchaseOrder|Transfer|Adjustment|null $source = null;
```

## When not to use

- You only have two or three parent types and the table is small — separate nullable FKs
  with `CHECK (exactly one is non-null)` give you referential integrity for free.
- You want JOINs in raw SQL — the polymorphic pattern makes ad-hoc queries awkward because
  the join target varies per row.
- The child table exists primarily to serve one parent type — a dedicated table with a real
  FK is simpler and more maintainable.
