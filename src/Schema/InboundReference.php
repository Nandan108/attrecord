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
 * **One instance per constraint, not per column.** A multi-column key is one rule, and splitting it
 * into a reference per column would describe rules nobody wrote: `(tenant_id, order_id) REFERENCES
 * orders (tenant, id)` is not a `tenant_id → tenant` constraint, and treating it as one reports any
 * row sharing a tenant as referencing every order in it.
 *
 * @api
 */
final class InboundReference
{
    /**
     * @param list<string> $childColumns      the referencing columns, in constraint order
     * @param list<string> $referencedColumns the columns of the *referenced* table they point at —
     *                                        paired positionally with `$childColumns`, and not
     *                                        always that table's primary key
     */
    public function __construct(
        /** The referencing table, as the catalogue names it — prefixed, since that is what exists. */
        public readonly string $childTable,
        public readonly array $childColumns,
        public readonly string $constraintName,
        public readonly array $referencedColumns,
        /**
         * The constraint's ON DELETE action, or null when the engine reports one this library has no
         * case for. Null rather than a guess: a caller reasoning about deletability should see "not
         * known" rather than a plausible wrong answer.
         */
        public readonly ?ForeignKeyAction $onDelete,
    ) {
    }

    /** Whether this constraint pairs more than one column — one rule over a tuple. */
    public function isComposite(): bool
    {
        return \count($this->childColumns) > 1;
    }

    /** Whether this constraint references `$column` of the table it points at. */
    public function references(string $column): bool
    {
        return \in_array($column, $this->referencedColumns, true);
    }
}
