<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares a **composite** primary key — `PRIMARY KEY (a, b)` — for a Record that exists to
 * describe a table, not to be read and written through attrecord's CRUD.
 *
 *     #[Table(name: 'article_tag')]
 *     #[PrimaryKey(columns: ['article_id', 'tag_id'])]
 *     final class ArticleTagRecord extends Record { ... }
 *
 * **DDL-only, and enforced as such.** Every CRUD path — `save()`, `find()`, `delete()`, the
 * `RecordSet` bulk writers, relation loading, `LockSet::acquire()` — assumes a single PK column
 * and **throws** on a Record declaring this attribute. That is deliberate: half-supporting a
 * composite key would mean `find($id)` quietly matching the first column, or a keyed upsert
 * targeting the wrong row. Refusing outright is the honest behaviour, and it is what makes the
 * narrow version of this feature safe to ship ahead of the wide one.
 *
 * What it *is* for: a table whose reads and writes are raw SQL (a hot-path state table, a
 * junction table, any "one row per (a, b)") but whose **shape** should still be declared in PHP,
 * so the DDL producer emits it and schema-evolution tooling can see it. Before this, such a table
 * had to be hand-written DDL — which the differ cannot compare against anything, so it silently
 * sat outside the managed schema and drifted unobserved.
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
