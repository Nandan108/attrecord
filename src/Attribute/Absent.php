<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares that a named schema object **must not exist** on this table — a retired index, unique
 * key, foreign key, CHECK or column that earlier releases created and this one does not.
 *
 *     #[Table(name: 'goods_receipts')]
 *     #[Absent(index: ['idx_legacy_sku', 'idx_legacy_isbn'], since: '1.4.0')]
 *     #[Absent(column: 'po_id', since: '1.4.0')]
 *     final class GoodsReceipt extends Record { ... }
 *
 * Each parameter takes one name or a list of them; the attribute is repeatable, so objects retired
 * in different releases get one declaration each. Declaring an object both present and absent is a
 * contradiction and throws where it is written.
 *
 * **This is an assertion about the present, not a record of an event** — which is what makes it
 * idempotent. On an install that never had the object there is simply nothing to do, so a schema
 * carrying `#[Absent]` still converges to an empty plan, and re-running it stays empty. That is the
 * property a `dropIndex()` migration step cannot have without a ledger key to remember itself by.
 *
 * **Inert in core**: collected onto {@see \Nandan108\Attrecord\Schema\TableSchema::$absent} and
 * never read by CRUD or the DDL producer — a `CREATE TABLE` describes what exists, and an absence
 * needs no expression there. The `attrecord-migrations` companion is what acts on it.
 *
 * ## What declaring it changes, and what it does not
 *
 * The differ already removes objects it finds live and undeclared; what this adds is *authority* —
 * the difference between "something is here that I do not recognise" and "I know exactly what that
 * is, and it should be gone". So the reclassification it buys is real but narrow:
 *
 * - **an index or unique key** becomes safe to drop unattended, where an unrecognised one is not:
 *   it may be an operator's tuning index, and dropping that silently degrades a query plan;
 * - **a foreign key or CHECK** was already safe to drop — an undeclared constraint contradicts the
 *   model rather than merely adding to it — so declaring it changes nothing but the reason text;
 * - **a column stays destructive**, because saying you meant it does not bring its values back.
 *   What the declaration buys there is provenance: an operator reading the plan can tell the
 *   deliberate removal from the surprise.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Absent
{
    /**
     * @param string|list<string>|null $index      retired non-unique index name(s)
     * @param string|list<string>|null $uniqueKey  retired unique key name(s)
     * @param string|list<string>|null $foreignKey retired foreign-key constraint name(s)
     * @param string|list<string>|null $check      retired CHECK constraint name(s), as **emitted**
     *                                             (`chk_…`) — see {@see Check} on why the emitted
     *                                             name is not the declared one
     * @param string|list<string>|null $column     retired column name(s) — dropping one destroys
     *                                             its data, so this still requires an explicit
     *                                             opt-in at apply time
     * @param string|null              $since      the release this object stopped being declared,
     *                                             for example `'1.4.0'`. **Opaque** — stored,
     *                                             reported in the plan, and never compared: this
     *                                             library does not know whether you ship semver,
     *                                             dates or plugin versions, and PHP's own
     *                                             `version_compare('1.04.0', '1.4.0')` answers
     *                                             "equal", so a guess here would be a quiet wrong
     *                                             answer. It is for you and for a pruning tool that
     *                                             knows your scheme.
     */
    public function __construct(
        public readonly string | array | null $index = null,
        public readonly string | array | null $uniqueKey = null,
        public readonly string | array | null $foreignKey = null,
        public readonly string | array | null $check = null,
        public readonly string | array | null $column = null,
        public readonly ?string $since = null,
    ) {
    }
}
