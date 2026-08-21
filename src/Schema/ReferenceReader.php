<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\DbSession;

/**
 * Answers "what points **at** this?" — the inbound direction of a foreign key, which the DDL
 * producer has no way to express because a Record only declares the keys it owns.
 *
 * Two questions, one built on the other: which columns anywhere in the schema reference a table
 * ({@see inboundForeignKeys()}), and, of a set of key values, which are actually referenced by a row
 * somewhere ({@see referencedKeys()}). The second is what a "you cannot delete this, N things use
 * it" answer is made of.
 *
 * **This is for reporting, not for making a delete safe.** A foreign key declared `ON DELETE
 * RESTRICT` already makes the delete safe — the engine refuses it, atomically, with no race. Asking
 * first and deleting after is a check-then-act with a gap in the middle; asking is for telling
 * someone *what* is holding a row, or counting how many rows are unreferenced, before anybody
 * attempts anything.
 *
 * **It sees only what the catalogue knows.** A referrer that stores a key without a foreign key —
 * an id inside a JSON document, a meta row, a table in another schema — is invisible here and
 * always will be. That is a property of the question, not a gap in the answer.
 *
 * A reader is **stateless about connections** but **caches the catalogue** it reads (see
 * {@see AbstractReferenceReader}), so hold one for the length of a request and no longer.
 *
 * @api
 */
interface ReferenceReader
{
    /**
     * Every foreign key in the current schema pointing at `$table`.
     *
     * @param string      $table  physical (prefixed) table name, as the database has it
     * @param string|null $column when given, only keys pointing at that column of `$table`
     *
     * @return list<InboundReference> in no guaranteed order
     */
    public function inboundForeignKeys(DbSession $session, string $table, ?string $column = null): array;

    /**
     * Of `$keys`, the ones some row somewhere actually references.
     *
     * Bulk by design: the whole set is answered in **one** statement — a UNION of the referencing
     * tables — because the alternative is a query per key, and a query per key over a catalogue read
     * is how a "which of these 500 are safe to remove" screen becomes a minute of database time.
     * There is no single-key primitive for the same reason; ask for a list of one.
     *
     * Returns the referenced *subset* — **the caller's own values**, in the order given, not the
     * driver's rendering of them, so `in_array($key, $result, true)`, `array_flip()` and
     * `array_diff()` all behave. (A signed BIGINT bound as an int comes back from mysqli as a
     * numeric string; a strict comparison against that would report a referenced row as free to
     * delete.) An empty result means nothing in `$keys` is referenced — which is also what comes
     * back, without touching the database, when no foreign key points at `$table.$column` at all.
     *
     * Large sets are split across statements internally: a UNION binds the key set once per
     * referring column, and every supported engine caps the parameters one statement may carry, so
     * "which of these 40 000 can I remove" would otherwise fail rather than merely take a moment.
     *
     * @param string       $column the referenced column on `$table` (often but not always its primary key)
     * @param list<scalar> $keys   values of that column
     *
     * @return list<scalar> the members of `$keys` that are referenced, in the order given
     */
    public function referencedKeys(DbSession $session, string $table, string $column, array $keys): array;
}
