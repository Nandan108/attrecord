<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
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
     * Memoized inbound lookups, keyed by table + column.
     *
     * `information_schema` scans cost real time on a server with thousands of tables, which is
     * ordinary shared hosting, and the answer only changes when the schema does. Caching per
     * instance rather than statically keeps the lifetime a caller's decision: hold a reader for a
     * request. A long-lived process that migrates its own schema should build a new one afterwards.
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
        return $this->cache[$table."\0".((string) $column)] ??= $this->readInbound($session, $table, $column);
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
            $branches[] = 'SELECT '.$this->dialect->quoteIdentifier($ref->childColumn).' AS k'
                .' FROM '.$this->dialect->quoteIdentifier($ref->childTable)
                .' WHERE '.$this->dialect->quoteIdentifier($ref->childColumn).' IN '.$placeholders;
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
     * The engine's own answer to "what points at this", uncached.
     *
     * @return list<InboundReference>
     */
    abstract protected function readInbound(DbSession $session, string $table, ?string $column): array;

    /**
     * Map an engine's ON DELETE spelling onto {@see ForeignKeyAction}, or null when it is one this
     * library has no case for. Null rather than a default: a caller weighing deletability is better
     * served by "not known" than by a plausible wrong answer.
     */
    final protected static function action(?string $raw): ?ForeignKeyAction
    {
        if (null === $raw) {
            return null;
        }

        return ForeignKeyAction::tryFrom(strtoupper(trim($raw)));
    }
}
