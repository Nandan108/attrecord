<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares that a column belongs to a named non-PK unique key.
 *
 * Apply to each column in the key using the same name. Single-column unique keys
 * use any unique name; compound keys share the same name across all member columns
 * (listed in declaration order).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class UniqueKey
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
