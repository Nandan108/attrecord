<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * A handle for referencing one column inside an upsert conflict-UPDATE SET expression, produced by
 * {@see Record::upsertCol()}. It carries the raw column name plus the dialect-rendered incoming/stored
 * row references, so a conditional/computed SET can be written as an **interpolated** expression
 * rather than a positional `sprintf`:
 *
 * ```php
 * $name = PluginPolicy::upsertCol('plugin_name');
 * $plugin->upsertByUniqueKey('uk_slug', [
 *     // keep the stored value unless a non-empty new one arrives — column named once, in upsertCol()
 *     ...$name->setRaw("CASE WHEN {$name->incoming} <> ? THEN {$name->incoming} ELSE {$name->stored} END", ['']),
 *     'last_seen_at' => new RawSql('CURRENT_TIMESTAMP(6)'),
 * ]);
 * ```
 *
 * The `->incoming` / `->stored` refs are bound to the dialect current when {@see Record::upsertCol()}
 * was called, so build the handle inside any `usingConnection()` / `usingSession()` scope that changes
 * the dialect. The refs are identifier references (never user data); bind literal *values* through the
 * `RawSql` `?` params.
 *
 * @api
 */
final class UpsertColumn
{
    /**
     * @param string $name     Raw (unquoted) column name — the key in the update-columns map
     * @param string $incoming Incoming-row value ref: `VALUES(\`col\`)` (MySQL/MariaDB) | `EXCLUDED."col"` (PG/SQLite)
     * @param string $stored   Stored-row value ref — the **table-qualified** column (`table.col`), so
     *                         it is unambiguous inside PostgreSQL's `ON CONFLICT DO UPDATE SET`
     */
    public function __construct(
        public readonly string $name,
        public readonly string $incoming,
        public readonly string $stored,
    ) {
    }

    /**
     * Build the single-entry update-columns fragment `[name => RawSql($sql, $params)]`, ready to
     * **spread** into {@see Record::upsertByUniqueKey()}'s `$updateColumns` — so the column name is
     * written once (in {@see Record::upsertCol()}) and never repeated as a map key:
     *
     * ```php
     * ...$name->setRaw("COALESCE(NULLIF({$name->incoming}, ?), {$name->stored})", ['']),
     * ```
     *
     * @param list<scalar|null> $params bound values for the expression's `?` placeholders
     *
     * @return array<string, RawSql>
     */
    public function setRaw(string $sql, array $params = []): array
    {
        return [$this->name => new RawSql($sql, $params)];
    }
}
