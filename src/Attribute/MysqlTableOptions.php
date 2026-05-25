<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * MySQL/MariaDB-specific table options applied during `CREATE TABLE` emission.
 *
 * This attribute is read **only** by {@see \Nandan108\Attrecord\Dialect\MysqlDialect}.
 * Other dialects (e.g. PostgreSQL) ignore it entirely — its presence on a Record
 * is harmless when targeting non-MySQL backends.
 *
 * Every field is nullable so users can override only the options they care about;
 * the dialect supplies sensible defaults (`InnoDB`, `utf8mb4`, `utf8mb4_unicode_ci`)
 * for any field left null and for Records that omit this attribute entirely.
 *
 *     #[Table(name: 'orders')]
 *     #[MysqlTableOptions(engine: 'Memory')]    // override engine only
 *     final class FastLookup extends Record { ... }
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MysqlTableOptions
{
    /**
     * @param string|null $engine    MySQL storage engine (e.g. 'InnoDB', 'MyISAM', 'Memory'). Null = dialect default.
     * @param string|null $charset   Default character set for the table (e.g. 'utf8mb4', 'latin1'). Null = dialect default.
     * @param string|null $collation Default collation (e.g. 'utf8mb4_unicode_ci', 'utf8mb4_bin'). Null = dialect default.
     */
    public function __construct(
        public readonly ?string $engine = null,
        public readonly ?string $charset = null,
        public readonly ?string $collation = null,
    ) {
    }
}
