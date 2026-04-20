<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares the physical table name and primary-key column for a Record subclass.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(
        public readonly string $name,
        public readonly string $primaryKey = 'id',
    ) {
    }
}
