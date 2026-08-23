<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Enum\SchemaObjectKind;

/**
 * One declared absence: a named schema object that must not exist on this table.
 *
 * **Inert in core** — see {@see \Nandan108\Attrecord\Attribute\Absent}, which is where the
 * reasoning lives. Collected onto {@see TableSchema::$absent} for the `attrecord-migrations`
 * companion to act on.
 *
 * @api
 */
final class AbsentDefinition
{
    public function __construct(
        public readonly SchemaObjectKind $kind,
        public readonly string $name,
        /** Release the object stopped being declared, opaque and never compared. */
        public readonly ?string $since = null,
    ) {
    }

    /** How a plan should describe this declaration, e.g. `declared absent since 1.4.0`. */
    public function describe(): string
    {
        return null === $this->since
            ? 'declared absent'
            : 'declared absent since '.$this->since;
    }
}
