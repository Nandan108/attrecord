<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * Bundles a DbSession and a SqlDialect into a single injectable unit.
 *
 * Inject once at bootstrap: Record::setConnection(new Connection($session, $dialect));
 *
 * @api
 */
final class Connection
{
    public function __construct(
        public readonly DbSession $session,
        public readonly SqlDialect $dialect,
    ) {
        // Bring the freshly-opened connection to the dialect's baseline (e.g. SQLite
        // WAL / busy_timeout / foreign_keys). Empty for MySQL/MariaDB and PostgreSQL, so this
        // is a no-op there.
        foreach ($dialect->connectionInitStatements() as $statement) {
            $session->exec($statement);
        }
    }
}
