<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\SqlDialect;

/**
 * Everything about {@see ReferenceReader} that is not the catalogue query: the caching, and the
 * one-statement UNION that turns a set of key values into the subset something references.
 *
 * A subclass supplies {@see readInbound()} and nothing else — the three engines disagree about
 * *where* the answer lives, never about what to do with it.
 */
abstract class AbstractReferenceReader implements ReferenceReader
{
    /**
     * Memoized inbound lookups, keyed by table.
     *
     * `information_schema` scans cost real time on a server with thousands of tables, which is
     * ordinary shared hosting, and the answer only changes when the schema does. Caching per
     * instance rather than statically keeps the lifetime a caller's decision: hold a reader for a
     * request. A long-lived process that migrates its own schema should build a new one afterwards.
     *
     * Keyed by table and not by table + column, because the column filter is applied **after** the
     * constraints are assembled: a multi-column key must be seen whole to be recognised as one, and
     * filtering it away in the catalogue query would hide every member but the one asked about. One
     * read then serves every column of that table.
     *
     * @var array<string, list<InboundReference>>
     */
    private array $cache = [];

    /**
     * Placeholders one statement may carry, conservatively under every supported engine's limit.
     *
     * The ceilings are lower and more varied than they look: MySQL and PostgreSQL both speak a
     * 16-bit parameter count (65535), and SQLite's `SQLITE_MAX_VARIABLE_NUMBER` defaults to 32766
     * on the versions attrecord supports. One budget under all three beats a per-dialect number
     * that would have to be right about a compile-time setting nobody can see from here.
     */
    private const MAX_BOUND_PARAMETERS = 20000;

    public function __construct(protected readonly SqlDialect $dialect)
    {
    }

    #[\Override]
    final public function inboundForeignKeys(DbSession $session, string $table, ?string $column = null): array
    {
        $all = $this->cache[$table] ??= $this->readInbound($session, $table);
        if (null === $column) {
            return $all;
        }

        return array_values(array_filter($all, static fn (InboundReference $r): bool => $r->references($column)));
    }

    #[\Override]
    final public function referencedKeys(DbSession $session, string $table, string $column, array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        $referrers = $this->inboundForeignKeys($session, $table, $column);
        if ([] === $referrers) {
            return []; // nothing can reference these — and no query needs to prove it
        }

        // A multi-column constraint cannot be answered from a list of single values: whether a row
        // references (tenant, id) depends on both members together, and this signature can only
        // carry one column's worth. Testing the asked-about member alone would report every row
        // sharing that value as a referrer — which reads as a correct "cannot delete" and is not.
        foreach ($referrers as $referrer) {
            if ($referrer->isComposite()) {
                throw new SchemaException(sprintf(
                    'referencedKeys(%s.%s): constraint "%s" on %s references (%s) as one key, so '
                    .'whether a row references a given %s depends on every member together. Ask '
                    .'about the whole key — this method answers about one column, and testing one '
                    .'member of a multi-column key reports rows that merely share that value.',
                    $table,
                    $column,
                    $referrer->constraintName,
                    $referrer->childTable,
                    implode(', ', $referrer->referencedColumns),
                    $column,
                ));
            }
        }

        // Every branch of the UNION binds the whole chunk, so the statement costs
        // referrers × keys placeholders. Past the engine's limit that is not a slow query but a
        // failed one, so the key set is split to stay under budget — a caller asking "which of these
        // 40 000 can I remove" is using the method exactly as intended and should not have to know.
        $perStatement = max(1, intdiv(self::MAX_BOUND_PARAMETERS, \count($referrers)));

        /** @psalm-var array<array-key, true> $found  keyed by the string form of each referenced value */
        $found = [];
        foreach (array_chunk($keys, $perStatement) as $chunk) {
            foreach ($this->fetchReferenced($session, $referrers, $chunk) as $value) {
                $found[$value] = true;
            }
        }

        // The caller's own values, in the caller's own order — not the driver's rendering of them.
        // A signed BIGINT bound as an int comes back from mysqli as a numeric *string*, so returning
        // what the database said would quietly break `in_array($key, $result, true)`, `array_flip()`
        // and `array_intersect_key()`, each of which is a natural thing to do with a key set. It
        // would report "unreferenced" for a row that is referenced, which on a delete screen is the
        // wrong direction to be wrong in. Matching on the string form and returning `$keys`'
        // members keeps the promise this method's signature makes.
        $referenced = [];
        foreach ($keys as $key) {
            if (isset($found[(string) $key])) {
                $referenced[] = $key;
            }
        }

        return $referenced;
    }

    /**
     * One statement: is any of `$chunk` referenced by any of `$referrers`?
     *
     * @param list<InboundReference> $referrers
     * @param list<scalar>           $chunk
     *
     * @return list<string> the referenced values, as the driver rendered them
     */
    private function fetchReferenced(DbSession $session, array $referrers, array $chunk): array
    {
        $placeholders = '('.implode(', ', array_fill(0, \count($chunk), '?')).')';
        $branches = [];
        $params = [];

        // One branch per referencing column, UNIONed — which also dedupes, since two referrers
        // holding the same key must yield that key once. Deliberately not one query per key: the
        // caller asked about a set, and answering a set one row at a time is how a bulk screen turns
        // into a minute of database time.
        foreach ($referrers as $ref) {
            // Single-column by construction: referencedKeys() refuses a composite referrer above.
            $childColumn = $ref->childColumns[0];
            $branches[] = 'SELECT '.$this->dialect->quoteIdentifier($childColumn).' AS k'
                .' FROM '.$this->dialect->quoteIdentifier($ref->childTable)
                .' WHERE '.$this->dialect->quoteIdentifier($childColumn).' IN '.$placeholders;
            foreach ($chunk as $key) {
                $params[] = $key;
            }
        }

        $values = [];
        foreach ($session->fetchAll(implode(' UNION ', $branches), $params) as $row) {
            $value = $row['k'] ?? null;
            if (null !== $value) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The engine's own answer to "what points at this", uncached and unfiltered — **one entry per
     * constraint**, with its column pairs in constraint order. The column filter is applied by
     * {@see inboundForeignKeys()} afterwards, on whole constraints.
     *
     * @return list<InboundReference>
     */
    abstract protected function readInbound(DbSession $session, string $table): array;

    /**
     * Assemble catalogue rows — one per column pair, in constraint order — into one reference per
     * constraint.
     *
     * @param iterable<array{constraint: string, table: string, child: string, referenced: string, onDelete: string|null}> $rows
     *
     * @return list<InboundReference>
     */
    final protected static function assemble(iterable $rows): array
    {
        /** @var array<string, array{table: string, child: list<string>, referenced: list<string>, onDelete: string|null}> $byConstraint */
        $byConstraint = [];

        foreach ($rows as $row) {
            // Keyed by child table *and* constraint name: engines scope a constraint name per
            // schema or per table, and two tables may hold identically named keys.
            $key = $row['table']."\0".$row['constraint'];
            $byConstraint[$key] ??= [
                'table'      => $row['table'],
                'child'      => [],
                'referenced' => [],
                'onDelete'   => $row['onDelete'],
            ];
            $byConstraint[$key]['child'][] = $row['child'];
            $byConstraint[$key]['referenced'][] = $row['referenced'];
        }

        $references = [];
        foreach ($byConstraint as $key => $acc) {
            $references[] = new InboundReference(
                childTable: $acc['table'],
                childColumns: $acc['child'],
                constraintName: substr($key, \strlen($acc['table']) + 1),
                referencedColumns: $acc['referenced'],
                // `static::`, not `self::`: PostgreSQL overrides this to read a one-character
                // spelling, and a self-bound call would silently hand every PG constraint a null
                // rule — "not known", which is exactly what a caller weighing a delete would trust.
                onDelete: static::action($acc['onDelete']),
            );
        }

        return $references;
    }

    /**
     * Map an engine's ON DELETE spelling onto {@see ForeignKeyAction}, or null when it is one this
     * library has no case for. Null rather than a default: a caller weighing deletability is better
     * served by "not known" than by a plausible wrong answer.
     *
     * Overridable because PostgreSQL spells the action as one character rather than a word.
     */
    protected static function action(?string $raw): ?ForeignKeyAction
    {
        if (null === $raw) {
            return null;
        }

        return ForeignKeyAction::tryFrom(strtoupper(trim($raw)));
    }
}
