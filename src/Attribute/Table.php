<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares the physical table name and primary-key column for a Record subclass.
 *
 * Only cross-dialect fields live here. MySQL-specific options (engine, charset,
 * collation) are carried by {@see MysqlTableOptions}; Postgres-specific options
 * by a future `PgsqlTableOptions`. The cross-dialect attribute stays portable
 * — each dialect reads its own dialect-specific class-level attribute (if any)
 * during DDL emission.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
    /**
     * @param string      $name       Unprefixed table name. The configured table prefix is applied at schema-build time.
     * @param string      $primaryKey primary-key **column** name (not property name); defaults to "id"
     * @param string|null $comment    table comment (DDL emission only); supported on both MySQL and Postgres but emitted with different syntax per dialect
     */
    public function __construct(
        public readonly string $name,
        public readonly string $primaryKey = 'id',
        public readonly ?string $comment = null,
    ) {
    }
}
