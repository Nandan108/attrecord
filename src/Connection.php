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
    }
}
