<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares a **composite** primary key — `PRIMARY KEY (a, b)` — so a table whose rows are
 * identified by several columns can be described in PHP and addressed through attrecord.
 *
 *     #[Table(name: 'article_tag')]
 *     #[PrimaryKey(columns: ['article_id', 'tag_id'])]
 *     final class ArticleTagRecord extends Record { ... }
 *
 * **A key is always given whole, as a map keyed by column name** —
 * `getOne(['owner_id' => 4, 'item_id' => 9])`, never a positional list. Ordering comes from this
 * declaration rather than the call site: a tuple written in the wrong order reads as valid and
 * addresses the wrong row, which is the same failure as `find($id)` quietly matching the first
 * column. {@see \Nandan108\Attrecord\Schema\TableSchema::normalizeKey()} refuses a key that is
 * partial, over-complete, or a bare scalar.
 *
 * **Not every path supports one yet, and those that do not refuse by name** rather than matching
 * on part of the key: `upsertByUniqueKey()`, `deleteUnreferenced()` and the `RecordSet` bulk
 * writers still throw. A refusal is the honest behaviour where the alternative is addressing rows
 * the caller never named — and it is what let the declaration ship before the CRUD did.
 *
 * Declaring the shape is worth it on its own, even for a table read and written by raw SQL (a
 * hot-path state table, a junction table, any "one row per (a, b)"): hand-written DDL is invisible
 * to the differ, so it sits outside the managed schema and drifts unobserved.
 *
 * Mutually exclusive with `#[Table(primaryKey:)]`: declaring both is a contradiction rather than
 * an override, and throws. Requires **at least two** columns — a one-column list is just
 * `#[Table(primaryKey:)]` spelled a longer way, and accepting it would create a second, silently
 * CRUD-hostile way to say something already expressible. Members must be declared columns, must
 * not repeat, and must not be auto-increment (no engine allows an auto-increment column to be a
 * non-leading part of a composite key, and a leading one would make the rest decorative).
 *
 * Column **names** (post-`name:`-override), not PHP property names — same convention as
 * {@see UniqueKey}'s class-level form.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PrimaryKey
{
    /** @param list<string> $columns ordered PK member column names; order is the physical key order and matters for index selectivity */
    public function __construct(
        public readonly array $columns,
    ) {
    }
}
