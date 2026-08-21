<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Enum\ForeignKeyAction;

/**
 * One foreign key pointing **at** a table: the inbound direction, as the live catalogue reports it.
 *
 * Deliberately not a {@see ForeignKeyDefinition}, which describes a constraint a Record *declares*
 * and resolves its target lazily. This is the other direction and a different source — read from the
 * database, about a child table that may have no Record at all (a hand-written table, another
 * application's). It carries physical names, verbatim, and resolves nothing.
 *
 * @api
 */
final class InboundReference
{
    public function __construct(
        /** The referencing table, as the catalogue names it — prefixed, since that is what exists. */
        public readonly string $childTable,
        /** The referencing column on that table. */
        public readonly string $childColumn,
        public readonly string $constraintName,
        /** The column of the *referenced* table this points at — not always its primary key. */
        public readonly string $referencedColumn,
        /**
         * The constraint's ON DELETE action, or null when the engine reports one this library has no
         * case for. Null rather than a guess: a caller reasoning about deletability should see "not
         * known" rather than a plausible wrong answer.
         */
        public readonly ?ForeignKeyAction $onDelete,
    ) {
    }
}
