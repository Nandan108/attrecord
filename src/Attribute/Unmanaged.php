<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares that a named schema object on this table is **someone else's** — present in the
 * database on purpose, by an authority other than this Record, and never to be converged or
 * dropped.
 *
 *     #[Table(name: 'orders')]
 *     #[Unmanaged(index: 'idx_dba_status_covering')]
 *     final class Order extends Record { ... }
 *
 * The canonical case is the tuning index a DBA added after a slow-query incident: it forbids
 * nothing, so it contradicts nothing, and the only thing that distinguishes it from a leftover of
 * our own is knowledge no amount of introspection can recover. Left undeclared it shows up as drift
 * forever, and a reader who sees the same false positive on every check learns to skim the check.
 *
 * ## Against {@see \Nandan108\AttrecordMigrations\PartiallyDeclared}
 *
 * That interface answers the same question at table granularity — *nothing* undeclared is ever
 * proposed for dropping — and pays for it by going quiet about genuine drift on that table for
 * good. This says the same thing about **one named object**, so everything else on the table stays
 * under the differ's eye. Prefer this where you can name what is not yours; the interface is for
 * tables whose shape is genuinely computed and cannot be named ahead of time.
 *
 * Unlike {@see Absent} this describes no transition and takes no version: an object that is not
 * ours does not become ours later. If it does, delete the line.
 *
 * **Inert in core** — collected onto {@see \Nandan108\Attrecord\Schema\TableSchema::$unmanaged}
 * and read only by the `attrecord-migrations` companion.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Unmanaged
{
    /**
     * @param string|list<string>|null $index      index name(s) belonging to another authority
     * @param string|list<string>|null $uniqueKey  unique key name(s)
     * @param string|list<string>|null $foreignKey foreign-key constraint name(s)
     * @param string|list<string>|null $check      CHECK constraint name(s), as **emitted**
     * @param string|list<string>|null $column     column name(s) another authority maintains — its
     *                                             shape is left alone as well as its existence,
     *                                             since converging a column we do not own would
     *                                             overwrite the type its owner chose
     */
    public function __construct(
        public readonly string | array | null $index = null,
        public readonly string | array | null $uniqueKey = null,
        public readonly string | array | null $foreignKey = null,
        public readonly string | array | null $check = null,
        public readonly string | array | null $column = null,
    ) {
    }
}
