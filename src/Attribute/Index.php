<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares a non-unique (secondary) index.
 *
 * Mirrors {@see UniqueKey} in shape; emits `KEY` rather than `UNIQUE KEY`.
 *
 * **Property-level form** (single-column or declaration-ordered composite):
 *
 *     #[Column(...)]
 *     #[Index('idx_orders_status')]
 *     public string $status = '';
 *
 * **Class-level form** (composite with explicit column ordering):
 *
 *     #[Table(name: 'orders')]
 *     #[Index('idx_status_date', columns: ['status', 'created_at'])]
 *     final class OrderRecord extends Record { ... }
 *
 * Class-level form **requires** `columns: [...]`; property-level form
 * **forbids** it. A given index name must be declared via one form only.
 * Column names in the `columns` list refer to **column names** (post-override),
 * not PHP property names.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Index
{
    /**
     * @param string            $name    index name (must be unique across the table)
     * @param list<string>|null $columns required when used at class level; forbidden at property level
     */
    public function __construct(
        public readonly string $name,
        public readonly ?array $columns = null,
    ) {
    }
}
