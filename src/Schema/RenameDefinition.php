<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

/**
 * A declared rename of a schema object: what it used to be called, and optionally when it changed.
 *
 * **Inert in core** — nothing here is read by CRUD or by the DDL producer, which only ever describe
 * the shape as it is now. It exists for the `attrecord-migrations` companion, where knowing that a
 * live object and a declared one are the *same thing* is the difference between renaming it and
 * creating a second one alongside it.
 *
 * @api
 */
final class RenameDefinition
{
    /**
     * @param string      $from  the object's previous name
     * @param string|null $since the release the rename shipped in, opaque to this library — see
     *                           {@see \Nandan108\Attrecord\Attribute\Absent::$since} for what a
     *                           version string is and is not good for here
     */
    public function __construct(
        public readonly string $from,
        public readonly ?string $since = null,
    ) {
    }
}
