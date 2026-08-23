<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares a non-PK unique key.
 *
 * **Property-level form** (single-column keys, or composites with column order
 * matching property declaration order):
 *
 *     #[Column(...)]
 *     #[UniqueKey('uk_external')]
 *     public string $external_ref = '';
 *
 *     // Composite: repeat the same name on each member property.
 *     // Column order follows property declaration order.
 *
 * **Class-level form** (composite keys with explicit column ordering):
 *
 *     #[Table(name: 'orders')]
 *     #[UniqueKey('uk_customer_date', columns: ['customer_id', 'created_at'])]
 *     final class OrderRecord extends Record { ... }
 *
 * Class-level form **requires** `columns: [...]`; property-level form
 * **forbids** it. A given key name must be declared via one form only.
 * Column names in the `columns` list refer to **column names** (post-override),
 * not PHP property names.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class UniqueKey
{
    /**
     * @param string            $name         index name (must be unique across the table)
     * @param list<string>|null $columns      required when used at class level; forbidden at property level
     * @param string|null       $renamedFrom  previous key name — see {@see Index::__construct()},
     *                                        which documents the parameter in full. **Inert in core.**
     * @param string|null       $renamedSince release the rename shipped in, opaque — see
     *                                        {@see Absent::$since}
     */
    public function __construct(
        public readonly string $name,
        public readonly ?array $columns = null,
        public readonly ?string $renamedFrom = null,
        public readonly ?string $renamedSince = null,
    ) {
    }
}
