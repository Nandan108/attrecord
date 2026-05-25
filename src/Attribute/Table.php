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
    /**
     * @param string      $name       Unprefixed table name. The configured table prefix is applied at schema-build time.
     * @param string      $primaryKey primary-key **column** name (not property name); defaults to "id"
     * @param string      $engine     MySQL storage engine. Ignored by non-MySQL dialects.
     * @param string      $charset    Default character set for the table. Ignored by non-MySQL dialects.
     * @param string      $collation  Default collation for the table. Ignored by non-MySQL dialects.
     * @param string|null $comment    table comment
     */
    public function __construct(
        public readonly string $name,
        public readonly string $primaryKey = 'id',
        public readonly string $engine = 'InnoDB',
        public readonly string $charset = 'utf8mb4',
        public readonly string $collation = 'utf8mb4_unicode_ci',
        public readonly ?string $comment = null,
    ) {
    }
}
