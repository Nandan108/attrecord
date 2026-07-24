<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Enum\OnConflict;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Enum\UpsertStrategy;
use Nandan108\Attrecord\Exception\AppendOnlyViolationException;
use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\Exception\RecordSaveException;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\RelationDefinition;
use Nandan108\Attrecord\Schema\TableSchema;

/**
 * A typed, iterable collection of Record instances.
 *
 * @api
 *
 * @template T of Record
 *
 * @implements \Iterator<int, T>
 * @implements \ArrayAccess<int, T>
 */
final class RecordSet implements \Iterator, \Countable, \ArrayAccess
{
    /** @var list<T> */
    private array $records;

    private int $position = 0;

    /**
     * @param list<T> $records
     */
    public function __construct(array $records = [])
    {
        $this->records = $records;
    }

    // -----------------------------------------------------------------
    // Basic accessors
    // -----------------------------------------------------------------

    /** @return T|null */
    public function first(): mixed
    {
        return $this->records[0] ?? null;
    }

    /** @return T|null */
    public function last(): mixed
    {
        $count = \count($this->records);

        return $count > 0 ? $this->records[$count - 1] : null;
    }

    /**
     * Extract field value(s) from each record, keyed by the record PK or custom key fields.
     *
     *   pluck('name')                    → [$pk => $name]
     *   pluck(['name', 'email'])          → [$pk => ['name' => …, 'email' => …]]
     *   pluck('name', 'group')            → ['groupA' => [$name1, $name2], …]
     *   pluck(['name', 'email'], 'group') → ['groupA' => [['name' => …, 'email' => …], …]]
     *
     * When no $keys are given the record PK is used as the outer key.
     * When $keys are given the same nested grouping logic as recordsGroupedByKeys() applies,
     * but the leaf values are the extracted field value(s) rather than the full Record.
     *
     * @param string|list<string> $fields
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string | array $fields, string ...$keys): array
    {
        if (empty($this->records)) {
            return [];
        }

        if (empty($keys)) {
            $pkProp = $this->records[0]::schema()->pkProp;
            $out = [];
            foreach ($this->records as $r) {
                /** @psalm-suppress MixedArrayOffset, MixedAssignment */
                $out[$r->{$pkProp}] = $this->extractFields($r, $fields);
            }

            return $out;
        }

        $out = [];
        foreach ($this->records as $r) {
            /** @psalm-suppress MixedAssignment */
            $bucket = &$out;
            foreach ($keys as $k) {
                /** @psalm-suppress MixedAssignment, MixedArrayOffset, MixedArrayAccess, MixedArrayAssignment, UnsupportedReferenceUsage */
                $bucket = &$bucket[$r->$k];
            }
            /** @psalm-suppress MixedAssignment, MixedArrayAssignment */
            $bucket[] = $this->extractFields($r, $fields);
            unset($bucket);
        }

        /** @var array<int|string, mixed> $out */
        return $out;
    }

    /**
     * @param string|list<string> $fields
     */
    private function extractFields(Record $r, string | array $fields): mixed
    {
        if (\is_string($fields)) {
            /** @psalm-suppress MixedReturnStatement */
            return $r->$fields;
        }

        $out = [];
        foreach ($fields as $f) {
            /** @psalm-suppress MixedAssignment */
            $out[$f] = $r->$f;
        }

        return $out;
    }

    /**
     * Index records by a unique column value.
     *
     * @return array<int|string, T>
     */
    public function recordsByKey(string $field): array
    {
        $out = [];
        foreach ($this->records as $r) {
            /** @psalm-suppress MixedArrayOffset */
            $out[$r->$field] = $r;
        }

        return $out;
    }

    /**
     * Group records by a single column value.
     *
     * @return array<int|string, self<T>>
     */
    public function recordsGroupedByKey(string $field): array
    {
        $groups = [];
        foreach ($this->records as $r) {
            /** @psalm-suppress MixedArrayOffset */
            $groups[$r->$field][] = $r;
        }

        return array_map(fn (array $g) => new self($g), $groups);
    }

    /**
     * Group records by one or more keys into a nested associative array.
     * Leaf buckets are wrapped in a RecordSet rather than left as plain arrays.
     *
     * @return array<int|string, mixed>
     */
    public function recordsGroupedByKeys(string $key, string ...$additionalKeys): array
    {
        $fields = [$key, ...$additionalKeys];
        $raw = [];
        foreach ($this->records as $r) {
            /** @psalm-suppress MixedAssignment */
            $bucket = &$raw;
            foreach ($fields as $f) {
                /** @psalm-suppress MixedAssignment, MixedArrayOffset, MixedArrayAccess, MixedArrayAssignment, UnsupportedReferenceUsage */
                $bucket = &$bucket[$r->$f];
            }
            /** @psalm-suppress PossiblyUndefinedMethod, MixedArrayAssignment */
            $bucket[] = $r;
            unset($bucket);
        }

        /** @var array<int|string, mixed> $raw */
        $result = $this->wrapLeaf($raw);

        return \is_array($result) ? $result : [];
    }

    /**
     * Recursively wrap leaf arrays (plain lists of Records) in RecordSet instances.
     * Intermediate nodes (arrays whose values are also arrays) are recursed into.
     * Returns a RecordSet for leaf nodes, or an array for intermediate nodes.
     *
     * @param array<int|string, mixed> $node
     */
    private function wrapLeaf(array $node): self | array
    {
        if ([] === $node) {
            return $node;
        }

        /** @psalm-suppress MixedAssignment */
        $first = reset($node);

        if ($first instanceof Record) {
            /** @var list<Record> $node */
            return new self($node);
        }

        $out = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($node as $key => $child) {
            /** @psalm-suppress MixedArgument, MixedAssignment */
            $out[$key] = \is_array($child) ? $this->wrapLeaf($child) : $child;
        }

        /** @var array<int|string, mixed> $out */
        return $out;
    }

    // -----------------------------------------------------------------
    // Bulk write operations
    // -----------------------------------------------------------------

    /**
     * Apply the same attribute bulk-assignment to every record in the set.
     *
     * Useful for stamping a shared field (e.g. updated_at, updated_by_actor_id)
     * across all records before a upsertAll() call.
     *
     * @param array<string, mixed> $attrs
     *
     * @return $this
     */
    public function bulkSet(array $attrs): static
    {
        foreach ($this->records as $r) {
            $r->set($attrs);
        }

        return $this;
    }

    /**
     * @deprecated Renamed to {@see upsertAll()} — the verb-precise name (its keyed path upserts),
     *             consistent with {@see upsertAllByUniqueKey()} and {@see insertAll()}. This alias
     *             forwards verbatim; migrate call sites and it will be removed.
     *
     * @codeCoverageIgnore
     */
    public function saveAll(bool $force = false, ?int $chunkSize = null, bool $allowInTransactionChunking = false): ?SaveResult
    {
        return $this->upsertAll($force, $chunkSize, $allowInTransactionChunking);
    }

    /**
     * Bulk **upsert** every dirty record in the set using a deadlock-safe strategy:
     *
     *  - New records (PK null): plain INSERT INTO … VALUES (…), (…)
     *  - Keyed records (PK set): 3-step pattern inside a transaction —
     *      1. INSERT IGNORE  — inserts truly new rows, skips existing
     *      2. SELECT pk … ORDER BY pk ASC FOR UPDATE  — deterministic lock order
     *      3. UPDATE … SET col = CASE pk WHEN … END  — applies changes
     *
     * Clean (unchanged) records are skipped; after a successful call all dirty records are marked
     * clean. New records' auto-increment PKs are back-filled onto the objects. For a **write-once**
     * table use {@see insertAll()} instead — this method's keyed path upserts (INSERT IGNORE +
     * update), which silently absorbs a duplicate-PK collision.
     *
     * @param bool                   $force                      upsert even records that dirty-tracking reports as clean
     * @param int|null               $chunkSize                  when non-null, split the write into $chunkSize-row transactions that
     *                                                           **commit independently** — bounds the lock/undo footprint for very
     *                                                           large batches, at the cost of whole-set atomicity (a mid-run failure
     *                                                           leaves earlier chunks committed, so the operation must be resumable).
     *                                                           When null (default) the whole set runs in ONE transaction, all-or-nothing.
     * @param bool                   $allowInTransactionChunking when a chunked write is issued *inside* an open
     *                                                           transaction, per-chunk commit is impossible (the outer transaction
     *                                                           holds every lock until it commits) and would silently leave the
     *                                                           footprint unbounded, so it is rejected by default. Pass true to chunk
     *                                                           anyway: the chunks run as separate, smaller statements within the
     *                                                           outer transaction — bounding statement size while staying **atomic**
     *                                                           (the outer transaction's contract), with the lock/undo footprint left
     *                                                           unbounded. No effect outside a transaction.
     * @param list<string>|null      $ignoreColumns              column names to drop from the write: an ignored column is left out of
     *                                                           the INSERT (its DB default fires) and out of the UPDATE SET (kept as
     *                                                           stored). `null`/`[]` ignore nothing. Unknown name throws SchemaException.
     * @param bool|list<string>|null $readBack                   re-read the written rows and hydrate them in place (see
     *                                                           {@see Record::save()}); `true` full row, `false` never, a
     *                                                           `list<string>` those columns, `null` = auto (ignored
     *                                                           nullable-with-default + generated). One batched `IN` query, not per row.
     * @param UpsertStrategy         $strategy                   {@see UpsertStrategy::Locked} (default) — the deadlock-safe 3-step.
     *                                                           {@see UpsertStrategy::Lockless} — one `INSERT … ON DUPLICATE KEY UPDATE`
     *                                                           / `… ON CONFLICT (pk) DO UPDATE` statement, no `SELECT … FOR UPDATE`;
     *                                                           opt-in, caller owns concurrency. Conflicts on the **PK**, applies a
     *                                                           **uniform** SET (use for homogeneous batches), does **not** back-fill
     *                                                           ids, and reports the raw driver affected-row count in `inserted`
     *                                                           (no insert/update split). See the enum for the full trade-offs.
     *
     * @return SaveResult|null inserted/updated counts, or null if nothing was dirty
     *
     * @throws RecordSaveException on DB error
     * @throws SchemaException     when $ignoreColumns or $readBack names a column not on the record
     */
    public function upsertAll(bool $force = false, ?int $chunkSize = null, bool $allowInTransactionChunking = false, ?array $ignoreColumns = null, bool | array | null $readBack = null, UpsertStrategy $strategy = UpsertStrategy::Locked): ?SaveResult
    {
        if (empty($this->records)) {
            return null;
        }

        $first = $this->records[0];
        // upsertAll() decides insert-vs-upsert per record at runtime, so it cannot be a reliable
        // append; append-only rows must go through insertAll(), which is insert-only.
        if ($first instanceof AppendOnly) {
            throw AppendOnlyViolationException::forOperation($first::class, 'upsertAll()');
        }
        $schema = $first::schema();
        $ignore = self::buildIgnoreSet($ignoreColumns, $schema, $first::class, 'upsertAll');
        // Validate the read-back mode up front; the 'auto' set is computed post-write.
        $readBackMode = Record::resolveReadBackMode($readBack, $schema, $first::class);
        $conn = $first::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;
        $pk = $schema->pk;
        $pkProp = $schema->pkProp;
        $pkColumn = $schema->columns[$pk];
        $pkAutoIncrement = $pkColumn->autoIncrement;
        $returningSuffix = $dialect->insertReturningSuffix($dialect->quoteIdentifier($pk));

        $dirtyRecords = $force
            ? $this->records
            : array_filter($this->records, fn (Record $r) => $r->isDirty());

        if (empty($dirtyRecords)) {
            return null;
        }

        // Columns actually changed on the UPDATE (keyed) records — captured before markClean() wipes
        // dirty state — so auto read-back can tell which generated columns those updates recompute.
        /** @var array<string, true> $updateDirty */
        $updateDirty = [];
        foreach ($dirtyRecords as $r) {
            $r->beforeSave();
            /** @psalm-suppress MixedPropertyFetch */
            $isInsert = null === $r->{$pkProp};
            $r->applyAutoTimestamps($isInsert);
            if ($isInsert) {
                $r->seedVersionForInsert();
            }
            $r->validate();
            if (!$isInsert) {
                foreach ($r->dirtyFields() as $dirtyCol => $_ignored) {
                    if (!isset($ignore[$dirtyCol])) {
                        $updateDirty[$dirtyCol] = true;
                    }
                }
            }
        }

        $dirtyRecords = array_values($dirtyRecords);

        // New (PK-null) records, captured BEFORE the write back-fills their ids — needed both for the
        // id back-fill (atomic path) and to compute the auto read-back set for either path.
        /** @var list<Record> $insertedRecords */
        $insertedRecords = array_values(array_filter(
            $dirtyRecords,
            static fn (Record $r): bool => null === $r->{$pkProp},
        ));

        // Resolve the read-back columns now, while insert/update state is still known.
        $readBackCols = 'auto' === $readBackMode
            ? $this->bulkAutoReadBackColumns($insertedRecords, $updateDirty, $schema, $ignore)
            : $readBackMode;

        // Opt-in lockless single-statement upsert: one INSERT … ON DUPLICATE KEY UPDATE / ON CONFLICT
        // DO UPDATE, no SELECT … FOR UPDATE (caller owns concurrency). Diverges from the locked
        // 3-step entirely, so it has its own execute path.
        if (UpsertStrategy::Lockless === $strategy) {
            return $this->upsertAllLockless(
                $dirtyRecords,
                $insertedRecords,
                $chunkSize,
                $allowInTransactionChunking,
                $session,
                $schema,
                $dialect,
                $pk,
                $pkProp,
                $ignore,
                $readBackCols,
            );
        }

        // Opt-in chunked path: many independent transactions, bounded footprint, not atomic.
        if (null !== $chunkSize) {
            if ($session->inTransaction() && !$allowInTransactionChunking) {
                // Per-chunk commit — the whole point of chunkSize — is impossible inside an open
                // transaction: the outer transaction holds every lock until it commits, so the
                // footprint stays unbounded. Fail loud rather than silently degrade to atomic;
                // $allowInTransactionChunking is the explicit acknowledgement to chunk anyway.
                throw new AttrecordException(
                    'upsertAll(chunkSize:) commits each chunk independently to bound the lock/undo '
                    .'footprint, which cannot happen inside an open transaction. Pass '
                    .'allowInTransactionChunking: true to chunk statements within the outer '
                    .'transaction anyway (atomic, but the footprint stays unbounded), call upsertAll() '
                    .'without chunkSize for a single atomic statement, or run the chunked write '
                    .'outside the transaction.',
                );
            }

            // When nested (with the flag), each chunk's transactional() runs inline in the outer
            // transaction — chunked statements, still atomic, footprint unbounded.
            $chunkedResult = $this->upsertAllChunked($dirtyRecords, $chunkSize, $session, $schema, $dialect, $pk, $pkProp, $pkColumn, $returningSuffix, $pkAutoIncrement, $ignore);
            if (null !== $readBackCols && [] !== $readBackCols) {
                $this->readBackAll($dirtyRecords, $session, $schema, $dialect, $pk, $pkProp, $readBackCols);
            }

            return $chunkedResult;
        }

        // Default: the whole set in ONE transaction — all-or-nothing. $insertedRecords (above) is in
        // the exact order buildPlan() will INSERT, so DB-generated ids back-fill onto the right objects.
        $plan = $this->buildPlan($dirtyRecords, $schema, $dialect, $ignore);
        if (null === $plan['insert'] && null === $plan['upsert']) {
            return null;
        }

        try {
            $counts = $session->transactional(
                fn (): array => $this->runPlan($session, $plan, $pk, $pkColumn, $returningSuffix, $pkAutoIncrement),
            );
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        $insertedIds = $counts['insertedIds'];

        // Back-fill DB-generated auto-increment ids onto the new records (mirrors save()), in INSERT
        // order, BEFORE markClean() so the refreshed snapshot includes the id. Application-minted PKs
        // (e.g. BINARY(16) UUIDs) yield no insertedIds, so this is a no-op for them.
        if ($pkAutoIncrement) {
            foreach ($insertedRecords as $index => $record) {
                if (array_key_exists($index, $insertedIds)) {
                    $record->{$pkProp} = $insertedIds[$index];
                }
            }
        }

        $insertedSet = [];
        foreach ($insertedRecords as $record) {
            $insertedSet[spl_object_id($record)] = true;
        }
        foreach ($dirtyRecords as $record) {
            $record->markClean();
            $record->afterSave(isset($insertedSet[spl_object_id($record)]));
        }

        if (null !== $readBackCols && [] !== $readBackCols) {
            $this->readBackAll($dirtyRecords, $session, $schema, $dialect, $pk, $pkProp, $readBackCols);
        }

        return new SaveResult($counts['inserted'], $counts['updated'], $insertedIds);
    }

    /**
     * Execute {@see UpsertStrategy::Lockless}: one `INSERT … VALUES (…),(…) ON DUPLICATE KEY UPDATE …`
     * (MySQL) / `… ON CONFLICT (pk) DO UPDATE SET …` (PG/SQLite) per chunk, keyed on the PK. No
     * `SELECT … FOR UPDATE`, no id back-fill (the DB resolves insert-vs-update per row and reports
     * only an affected-row count). Records are already `beforeSave()`/`validate()`'d.
     *
     * `afterSave(wasInsert:)` is best-effort — a record that had no PK before the write is reported
     * as an insert, one that carried a PK as an update — because the DB does not report the per-row
     * outcome. `SaveResult::$inserted` carries the summed raw driver affected-row count; `$updated`
     * is 0 (see {@see UpsertStrategy}).
     *
     * @param list<Record>            $dirtyRecords    already hooked/validated
     * @param list<Record>            $insertedRecords the PK-null subset (for the afterSave best-effort flag)
     * @param array<string, true>     $ignore          columns to drop from the write
     * @param 'all'|list<string>|null $readBackCols    resolved read-back columns (null/[] = none)
     */
    private function upsertAllLockless(array $dirtyRecords, array $insertedRecords, ?int $chunkSize, bool $allowInTransactionChunking, DbSession $session, TableSchema $schema, SqlDialect $dialect, string $pk, string $pkProp, array $ignore, string | array | null $readBackCols): SaveResult
    {
        // Chunking here only bounds statement size — there is no FOR UPDATE to order, so no PK sort.
        // The in-transaction guard still applies: per-chunk commit is impossible inside an open
        // transaction, so reject it unless explicitly allowed (mirrors the locked path).
        if (null !== $chunkSize && $session->inTransaction() && !$allowInTransactionChunking) {
            throw new AttrecordException(
                'upsertAll(strategy: Lockless, chunkSize:) commits each chunk independently to bound '
                .'statement size, which cannot happen inside an open transaction. Pass '
                .'allowInTransactionChunking: true to chunk within the outer transaction, or call '
                .'without chunkSize for a single statement.',
            );
        }

        $chunks = (null === $chunkSize || $chunkSize < 1)
            ? [$dirtyRecords]
            : array_chunk($dirtyRecords, $chunkSize);

        $affected = 0;
        foreach ($chunks as $chunk) {
            // array_chunk never yields an empty chunk, and the PK is always in the column set, so
            // buildLocklessUpsertSql() always produces a statement.
            $sql = $this->buildLocklessUpsertSql($chunk, $schema, $dialect, $ignore, $pk);
            try {
                /** @psalm-suppress MixedAssignment */
                $affected += $session->transactional(static fn (): int => $session->exec($sql));
            } catch (\Throwable $e) {
                throw new RecordSaveException($e->getMessage(), $e);
            }
        }

        $insertedSet = [];
        foreach ($insertedRecords as $record) {
            $insertedSet[spl_object_id($record)] = true;
        }
        foreach ($dirtyRecords as $record) {
            $record->markClean();
            $record->afterSave(isset($insertedSet[spl_object_id($record)]));
        }

        if (null !== $readBackCols && [] !== $readBackCols) {
            $this->readBackAll($dirtyRecords, $session, $schema, $dialect, $pk, $pkProp, $readBackCols);
        }

        return new SaveResult($affected, 0, []);
    }

    /**
     * Build the single-statement upsert (the engine's `ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE`)
     * for one chunk (keyed on the PK). Column set = the PK
     * (always present, so the statement is never empty) plus every non-generated, non-ignored column
     * that is non-null or dirty on some record; the SET updates the union of dirty non-PK columns,
     * each to its incoming value ({@see SqlDialect::incomingRef()}). An empty update set degrades to
     * insert-or-ignore inside {@see SqlDialect::buildBulkUpsertSql()}.
     *
     * @param list<Record>        $records the caller passes a non-empty chunk
     * @param array<string, true> $ignore
     */
    private function buildLocklessUpsertSql(array $records, TableSchema $schema, SqlDialect $dialect, array $ignore, string $pk): string
    {
        $presentCols = [$pk => true];
        /** @var array<string, null> $updateCols col => null (copy the incoming value) */
        $updateCols = [];
        foreach ($records as $record) {
            $recDirty = $record->dirtyFields();
            foreach ($schema->columns as $colName => $col) {
                // Generated columns are DB-computed; ignored columns are caller-dropped. The PK seeds
                // $presentCols and is never an update target.
                if ($col->isGenerated || isset($ignore[$colName])) {
                    continue;
                }
                $isDirty = isset($recDirty[$colName]);
                if (($record->{$col->propertyName} ?? null) !== null || $isDirty) {
                    $presentCols[$colName] = true;
                }
                if ($isDirty && $colName !== $pk) {
                    $updateCols[$colName] = null;
                }
            }
        }

        $colNames = array_values(array_filter(
            array_keys($schema->columns),
            static fn ($n) => isset($presentCols[$n]),
        ));

        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($colNames as $colName) {
                $col = $schema->columns[$colName];
                $row[] = $dialect->toLiteral(
                    ColumnSerializer::toDbValue($record->{$col->propertyName} ?? null, $col),
                    $col,
                );
            }
            $rows[] = $row;
        }

        return $dialect->buildBulkUpsertSql($schema->tableName, [$pk], $colNames, $rows, $updateCols);
    }

    /**
     * The auto read-back columns for a bulk write: from the newly-inserted records, the omitted
     * default + generated columns (via {@see Record::autoReadBackColumns()} over their written set);
     * unioned with the generated columns the UPDATE (keyed) records' changed columns recompute.
     * Empty when nothing diverged, so auto stays a no-op on a pure update of plain columns.
     *
     * @param list<Record>        $insertedRecords the PK-null records (captured before the write)
     * @param array<string, true> $updateDirty     columns changed by the keyed (UPDATE) records
     * @param array<string, true> $ignore
     *
     * @return list<string>
     */
    private function bulkAutoReadBackColumns(array $insertedRecords, array $updateDirty, TableSchema $schema, array $ignore): array
    {
        $cols = [];
        if (!empty($insertedRecords)) {
            $insertWritten = $this->bulkInsertWrittenColumns($insertedRecords, $schema, $ignore);
            foreach (Record::autoReadBackColumns($schema, $insertWritten, true) as $c) {
                $cols[$c] = true;
            }
        }
        foreach ($schema->generatedColumnsAffectedBy($updateDirty) as $c) {
            $cols[$c] = true;
        }

        return array_keys($cols);
    }

    /**
     * Bulk **insert-only** writer: one plain `INSERT INTO … VALUES (…), (…)` covering every record
     * in the set, in a single statement and one transaction. Unlike {@see upsertAll()}, it applies
     * **no upsert semantics** — a duplicate PK raises a DB error (wrapped in RecordSaveException)
     * rather than being silently ignored or overwritten, and no `SELECT … FOR UPDATE` is taken.
     *
     * This is the correct primitive for **append-only, client-minted-PK** tables — ledgers, event
     * logs, outboxes — where rows are written once and a PK collision is a bug to surface loudly,
     * not a row to update. upsertAll() cannot serve them: a record carrying a PK routes into its
     * keyed-upsert path (INSERT IGNORE → FOR UPDATE → CASE-UPDATE), which both masks the collision
     * and takes locks the append never needed.
     *
     * Every record is inserted (no dirty filtering — an appended row is written whole); `beforeSave()`,
     * `#[CreatedAt]`/`#[UpdatedAt]` timestamps (as new), and `validate()` fire per record, then
     * `markClean()` + `afterSave(isNew: true)` afterwards. The PK column is written for
     * application-minted PKs; auto-increment and DB-generated columns are excluded and, on an
     * auto-increment table, generated ids are back-filled onto the records in INSERT order (which
     * requires the batch to be homogeneous — either all PK-null on an auto-increment table, or all
     * PK-carrying on a minted-PK table; a mixed batch on an auto-increment table misaligns the
     * back-fill and is unsupported).
     *
     * @param list<string>|null      $ignoreColumns column names to drop from the INSERT so their DB
     *                                              default fires; unknown name throws SchemaException
     * @param bool|list<string>|null $readBack      re-read the inserted rows and hydrate them (see
     *                                              {@see Record::save()}); `true` full row, `false` never,
     *                                              a `list<string>` those columns, `null` = auto (ignored
     *                                              nullable-with-default + generated columns). Under
     *                                              {@see OnConflict::Ignore} on an auto-increment table
     *                                              the skipped records keep a null PK, so read-back can
     *                                              only reach client-minted-PK records.
     * @param OnConflict             $onConflict    {@see OnConflict::Fail} (default) surfaces a key
     *                                              collision as a DB error; {@see OnConflict::Ignore}
     *                                              skips colliding rows (insert-or-ignore) — the PK is
     *                                              then NOT back-filled (a mixed insert/skip batch cannot
     *                                              be aligned), and $inserted counts only real inserts
     *
     * @return SaveResult|null inserted count (updated is always 0), or null if the set is empty
     *                         or no insertable column carries a value
     *
     * @throws RecordSaveException on DB error (a duplicate-PK collision under {@see OnConflict::Fail})
     * @throws SchemaException     when $ignoreColumns or $readBack names a column not on the record
     */
    public function insertAll(?array $ignoreColumns = null, bool | array | null $readBack = null, OnConflict $onConflict = OnConflict::Fail): ?SaveResult
    {
        if (empty($this->records)) {
            return null;
        }

        $ignoreConflicts = OnConflict::Ignore === $onConflict;
        $records = $this->records;
        $first = $records[0];
        $schema = $first::schema();
        $ignore = self::buildIgnoreSet($ignoreColumns, $schema, $first::class, 'insertAll');
        // Validate the read-back mode up front; the 'auto' set is computed post-write.
        $readBackMode = Record::resolveReadBackMode($readBack, $schema, $first::class);
        $conn = $first::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;
        $pk = $schema->pk;
        $pkProp = $schema->pkProp;
        $pkColumn = $schema->columns[$pk];
        $pkAutoIncrement = $pkColumn->autoIncrement;
        $returningSuffix = $dialect->insertReturningSuffix($dialect->quoteIdentifier($pk));

        foreach ($records as $r) {
            $r->beforeSave();
            // insertAll always INSERTs (never upserts), so every record is new — stamp #[CreatedAt]
            // and #[UpdatedAt] as such, regardless of whether the PK is DB-generated or app-minted.
            $r->applyAutoTimestamps(true);
            $r->seedVersionForInsert();
            $r->validate();
        }

        $insertSql = $this->buildBulkInsertSql($records, $schema, $dialect, $ignore, $ignoreConflicts);
        if (null === $insertSql) {
            return null;
        }

        // Reuse runPlan's insert execution (PG RETURNING / MySQL lastInsertId + AI id capture);
        // upsert stays null so no FOR UPDATE / CASE-UPDATE is ever emitted.
        $plan = ['insert' => $insertSql, 'upsert' => null];
        try {
            $counts = $session->transactional(
                fn (): array => $this->runPlan($session, $plan, $pk, $pkColumn, $returningSuffix, $pkAutoIncrement),
            );
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        $insertedIds = $counts['insertedIds'];

        // Back-fill DB-generated auto-increment ids onto the (PK-null) records, in INSERT order.
        // Application-minted PKs yield no insertedIds, so this is a no-op for them. Skipped under
        // OnConflict::Ignore: a partial insert leaves fewer ids than records with no way to tell
        // which record each id belongs to, so position-based back-fill would misalign.
        if ($pkAutoIncrement && !$ignoreConflicts) {
            $newRecords = array_values(array_filter(
                $records,
                static fn (Record $r): bool => null === $r->{$pkProp},
            ));
            foreach ($newRecords as $index => $record) {
                if (array_key_exists($index, $insertedIds)) {
                    $record->{$pkProp} = $insertedIds[$index];
                }
            }
        }

        foreach ($records as $record) {
            $record->markClean();
            $record->afterSave(true);
        }

        $readBackCols = 'auto' === $readBackMode
            ? Record::autoReadBackColumns($schema, $this->bulkInsertWrittenColumns($records, $schema, $ignore), true)
            : $readBackMode;
        if (null !== $readBackCols && [] !== $readBackCols) {
            $this->readBackAll($records, $session, $schema, $dialect, $pk, $pkProp, $readBackCols);
        }

        return new SaveResult($counts['inserted'], 0, $insertedIds);
    }

    /**
     * Return the single INSERT that {@see insertAll()} would execute for the current set, without
     * touching the DB (test/inspection helper, mirroring {@see buildUpsertAllSql()}). Built from
     * current record state; hooks/timestamps/validation are NOT run. Returns null if the set is empty
     * or no insertable column carries a value.
     *
     * @param list<string>|null $ignoreColumns column names to drop from the INSERT
     * @param OnConflict        $onConflict    {@see OnConflict::Ignore} appends the insert-or-ignore clause
     */
    public function buildInsertAllSql(?array $ignoreColumns = null, OnConflict $onConflict = OnConflict::Fail): ?string
    {
        if (empty($this->records)) {
            return null;
        }

        $first = $this->records[0];
        $schema = $first::schema();

        return $this->buildBulkInsertSql(
            $this->records,
            $schema,
            $first::connection()->dialect,
            self::buildIgnoreSet($ignoreColumns, $schema, $first::class, 'insertAll'),
            OnConflict::Ignore === $onConflict,
        );
    }

    /**
     * Execute one built plan (bulk INSERT + the deadlock-safe 3-step upsert) inside the caller's
     * transaction and report the row counts + any DB-generated insert ids. Shared by the atomic
     * (whole-set) and chunked paths.
     *
     * @param array{insert: string|null, upsert: UpsertSql|null} $plan
     *
     * @return array{inserted: int, updated: int, insertedIds: list<int|string>}
     */
    private function runPlan(DbSession $session, array $plan, string $pk, ColumnDefinition $pkColumn, string $returningSuffix, bool $pkAutoIncrement): array
    {
        $inserted = 0;
        $updated = 0;
        /** @var list<int|string> $insertedIds */
        $insertedIds = [];

        if (null !== $plan['insert']) {
            if ('' !== $returningSuffix) {
                // PostgreSQL: RETURNING gives back the generated PKs directly. Cast each through
                // fromDb (PG returns bigint as a string) so insertedIds and the back-filled record
                // PKs are ints, matching the MySQL path.
                $rows = $session->fetchAll($plan['insert']."\n".$returningSuffix);
                foreach ($rows as $row) {
                    /**
                     * @psalm-suppress MixedArgument
                     *
                     * @var int|string $id
                     */
                    $id = ColumnSerializer::fromDb($row[$pk], $pkColumn, $row);
                    $insertedIds[] = $id;
                }
                $inserted += \count($rows);
            } else {
                // MySQL/MariaDB: lastInsertId() is the first ID; the range is sequential. Only
                // meaningful for auto-increment PKs — for application-minted PKs (e.g. BINARY(16)
                // UUIDs) the caller already knows the IDs, so leave $insertedIds empty.
                $n = $session->exec($plan['insert']);
                if ($n > 0 && $pkAutoIncrement) {
                    $firstId = (int) $session->lastInsertId();
                    for ($i = 0; $i < $n; ++$i) {
                        $insertedIds[] = $firstId + $i;
                    }
                }
                $inserted += $n;
            }
        }

        if (null !== $plan['upsert']) {
            $inserted += $session->exec($plan['upsert']->create);
            $session->exec($plan['upsert']->lock);
            if (null !== $plan['upsert']->update) {
                $updated += $session->exec($plan['upsert']->update);
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'insertedIds' => $insertedIds];
    }

    /**
     * Chunked upsert with per-chunk commit (opt-in via {@see upsertAll()}'s `$chunkSize`). Bounds the
     * lock/undo footprint at `$chunkSize` rows per transaction; **not** all-or-nothing — a failure
     * part-way leaves already-committed chunks in place, so the operation must be safe to resume.
     *
     * New (PK-null) records are chunked for INSERT; keyed records are **sorted by PK ascending**
     * before chunking so each chunk's step-2 `FOR UPDATE` locks a contiguous ascending range and
     * chunks proceed low→high — preserving the global ascending-PK lock-order invariant (see
     * docs/arch-concurrency.md). Assumes the PK's PHP sort order matches the database's `ORDER BY`
     * (true for integer and binary PKs).
     *
     * @param list<Record>        $dirtyRecords already beforeSave()'d and validate()'d
     * @param array<string, true> $ignore       column names to drop from the write
     */
    private function upsertAllChunked(array $dirtyRecords, int $chunkSize, DbSession $session, TableSchema $schema, SqlDialect $dialect, string $pk, string $pkProp, ColumnDefinition $pkColumn, string $returningSuffix, bool $pkAutoIncrement, array $ignore = []): SaveResult
    {
        if ($chunkSize < 1) {
            $chunkSize = \count($dirtyRecords);
        }

        $newRecords = [];
        $keyedRecords = [];
        foreach ($dirtyRecords as $r) {
            if (null === $r->{$pkProp}) {
                $newRecords[] = $r;
            } else {
                $keyedRecords[] = $r;
            }
        }
        // Ascending-PK order so chunk boundaries are contiguous ranges and locks are taken low→high.
        /** @psalm-suppress MixedArgument */
        usort($keyedRecords, static fn (Record $a, Record $b): int => $a->{$pkProp} <=> $b->{$pkProp});

        // New-record chunks (INSERT) first, then keyed chunks (upsert); each homogeneous so id
        // back-fill maps 1:1 to a chunk's records and insert-before-upsert ordering is preserved.
        /** @var list<list<Record>> $chunks */
        $chunks = [
            ...array_chunk($newRecords, $chunkSize),
            ...array_chunk($keyedRecords, $chunkSize),
        ];

        $totalInserted = 0;
        $totalUpdated = 0;
        /** @var list<int|string> $allInsertedIds */
        $allInsertedIds = [];

        foreach ($chunks as $chunk) {
            // Capture inserts (PK-null) before the write back-fills their ids, for afterSave().
            $chunkInsertSet = [];
            foreach ($chunk as $r) {
                if (null === $r->{$pkProp}) {
                    $chunkInsertSet[spl_object_id($r)] = true;
                }
            }

            $plan = $this->buildPlan($chunk, $schema, $dialect, $ignore);
            if (null === $plan['insert'] && null === $plan['upsert']) {
                continue;
            }

            try {
                $counts = $session->transactional(
                    fn (): array => $this->runPlan($session, $plan, $pk, $pkColumn, $returningSuffix, $pkAutoIncrement),
                );
            } catch (\Throwable $e) {
                throw new RecordSaveException($e->getMessage(), $e);
            }

            $insertedIds = $counts['insertedIds'];

            // Back-fill this chunk's new records (still PK-null until now), in INSERT order.
            if ($pkAutoIncrement && [] !== $insertedIds) {
                $newInChunk = array_values(array_filter(
                    $chunk,
                    static fn (Record $r): bool => null === $r->{$pkProp},
                ));
                foreach ($newInChunk as $index => $record) {
                    if (array_key_exists($index, $insertedIds)) {
                        $record->{$pkProp} = $insertedIds[$index];
                    }
                }
            }

            foreach ($chunk as $record) {
                $record->markClean();
                $record->afterSave(isset($chunkInsertSet[spl_object_id($record)]));
            }

            $totalInserted += $counts['inserted'];
            $totalUpdated += $counts['updated'];
            $allInsertedIds = [...$allInsertedIds, ...$insertedIds];
        }

        return new SaveResult($totalInserted, $totalUpdated, $allInsertedIds);
    }

    /**
     * Bulk upsert keyed by a declared #[UniqueKey], **without burning auto-increment ids** on
     * the rows that already exist.
     *
     * One `SELECT … WHERE (conflict cols) IN (…)` resolves the PKs of existing rows; those
     * records get their PK set so {@see upsertAll()} routes them through its keyed-upsert (which
     * supplies the PK, so MySQL allocates no auto-increment value), while genuinely-new records
     * (PK still null) go through upsertAll's plain bulk INSERT (one AI value each, none wasted).
     * Net: the loop-free, burn-free counterpart of an `INSERT … ON DUPLICATE KEY UPDATE` batch.
     *
     * Records that already carry a PK are left untouched (already keyed). The SELECT-then-write
     * is not atomic — a concurrent insert of the same conflict tuple would fall back to
     * upsertAll's INSERT-IGNORE path (one burned id) — acceptable for the low-concurrency
     * registry/config writes this targets.
     *
     * @param string $conflictKey name of a #[UniqueKey] declared on the record class
     *
     * @throws AttrecordException when $conflictKey is not declared on the record class
     */
    public function upsertAllByUniqueKey(string $conflictKey): ?SaveResult
    {
        if (empty($this->records)) {
            return null;
        }

        $first = $this->records[0];
        if ($first instanceof AppendOnly) {
            throw AppendOnlyViolationException::forOperation($first::class, 'upsertAllByUniqueKey()');
        }
        $schema = $first::schema();

        $conflictCols = $schema->uniqueKeys[$conflictKey]
            ?? throw new AttrecordException(
                sprintf('upsertAllByUniqueKey: unknown unique key "%s" on %s.', $conflictKey, $first::class),
            );

        $this->resolveExistingPksByUniqueKey($schema, $conflictCols);

        return $this->upsertAll();
    }

    /**
     * For each PK-less record whose conflict-key columns are all set, look up the existing
     * row's PK (one batched `IN` query) and assign it, so a subsequent {@see upsertAll()} updates
     * rather than inserts. In-memory pairing; no per-record query.
     *
     * @param list<string> $conflictCols
     */
    private function resolveExistingPksByUniqueKey(TableSchema $schema, array $conflictCols): void
    {
        $first = $this->records[0];
        $conn = $first::connection();
        $dialect = $conn->dialect;
        $pkProp = $schema->pkProp;

        /** @var list<list<scalar|null>> $tuples distinct conflict-value tuples to look up */
        $tuples = [];
        /** @var array<string, list<Record>> $recordsByTuple */
        $recordsByTuple = [];

        foreach ($this->records as $record) {
            if (null !== $record->{$pkProp}) {
                continue; // already keyed → upsertAll handles it directly
            }
            $values = [];
            $allSet = true;
            foreach ($conflictCols as $colName) {
                $col = $schema->columns[$colName];
                /** @psalm-suppress MixedAssignment */
                $value = $record->{$col->propertyName} ?? null;
                if (null === $value) {
                    $allSet = false;
                    break;
                }
                $values[] = ColumnSerializer::toParam($value, $col, $dialect->bindsBinaryAsLob());
            }
            if (!$allSet) {
                continue;
            }
            $tupleKey = implode("\0", array_map(static fn ($v): string => (string) $v, $values));
            if (!isset($recordsByTuple[$tupleKey])) {
                $tuples[] = $values;
            }
            $recordsByTuple[$tupleKey][] = $record;
        }

        if (empty($tuples)) {
            return;
        }

        $pk = $schema->pk;
        $quotedConflict = implode(', ', array_map($dialect->quoteIdentifier(...), $conflictCols));
        $rowPlaceholder = '('.implode(', ', array_fill(0, count($conflictCols), '?')).')';
        $inList = implode(', ', array_fill(0, count($tuples), $rowPlaceholder));
        $params = [];
        foreach ($tuples as $tuple) {
            foreach ($tuple as $value) {
                $params[] = $value;
            }
        }

        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE (%s) IN (%s)',
            $dialect->quoteIdentifier($pk),
            $quotedConflict,
            $dialect->quoteIdentifier($schema->tableName),
            $quotedConflict,
            $inList,
        );

        /** @psalm-suppress MixedArgumentTypeCoercion */
        $rows = $conn->session->fetchAll($sql, $params);

        foreach ($rows as $row) {
            $values = [];
            foreach ($conflictCols as $colName) {
                $values[] = (string) ($row[$colName] ?? '');
            }
            $tupleKey = implode("\0", $values);
            $pkValue = $row[$pk] ?? null;
            foreach ($recordsByTuple[$tupleKey] ?? [] as $record) {
                $record->{$pkProp} = ColumnSerializer::fromDb($pkValue, $schema->columns[$pk], $row);
            }
        }
    }

    /**
     * @deprecated Renamed to {@see buildUpsertAllSql()} alongside {@see upsertAll()}. Forwards verbatim.
     *
     * @codeCoverageIgnore
     */
    public function buildSaveAllSql(bool $force = false): ?UpsertSql
    {
        return $this->buildUpsertAllSql($force);
    }

    /**
     * Return the upsert plan that {@see upsertAll()} would execute for keyed (known-PK) records,
     * without touching the DB. Returns null if there are no dirty keyed records.
     *
     * Useful for test assertions on the generated SQL.
     * New records (PK null) produce a plain INSERT executed separately by upsertAll();
     * use CapturingDbSession + upsertAll() to inspect that statement.
     *
     * @param list<string>|null $ignoreColumns column names to drop from the write
     */
    public function buildUpsertAllSql(bool $force = false, ?array $ignoreColumns = null): ?UpsertSql
    {
        if (empty($this->records)) {
            return null;
        }

        $first = $this->records[0];
        $schema = $first::schema();
        $conn = $first::connection();

        $dirty = $force
            ? $this->records
            : array_filter($this->records, fn (Record $r) => $r->isDirty());
        $dirty = array_values($dirty);

        if (empty($dirty)) {
            return null;
        }

        $ignore = self::buildIgnoreSet($ignoreColumns, $schema, $first::class, 'upsertAll');
        $plan = $this->buildPlan($dirty, $schema, $conn->dialect, $ignore);

        return $plan['upsert'];
    }

    /**
     * Validate a caller-supplied column-name ignore list against the schema and return it as a
     * lookup set. Mirrors `Record::save(ignoreColumns:)` — an unknown name throws up front so a
     * typo fails loudly rather than silently writing a column the caller meant to drop.
     *
     * @param list<string>|null $ignoreColumns
     *
     * @return array<string, true>
     */
    private static function buildIgnoreSet(?array $ignoreColumns, TableSchema $schema, string $recordClass, string $method): array
    {
        $ignore = [];
        foreach ($ignoreColumns ?? [] as $name) {
            if (!isset($schema->columns[$name])) {
                throw new SchemaException(
                    sprintf('%s(ignoreColumns:): unknown column "%s" on %s.', $method, $name, $recordClass),
                );
            }
            $ignore[$name] = true;
        }

        return $ignore;
    }

    /**
     * Re-read the given (already-written, PK-carrying) records from the DB in one `IN` query and
     * hydrate them in place, so columns the write omitted — an ignored column whose DB default
     * fired, plus DB-generated columns — reflect their stored values and the records read back
     * clean (fires afterLoad() per record). Records are matched to rows by **ascending-PK order**
     * (same ordering the chunked path uses), which also sidesteps PostgreSQL's single-read bytea
     * stream (the PK is never consumed before hydrateFromRow()).
     *
     * @param list<Record>       $records
     * @param 'all'|list<string> $rbCols  'all' = full-row reload; a list = patch only those columns
     */
    private function readBackAll(array $records, DbSession $session, TableSchema $schema, SqlDialect $dialect, string $pk, string $pkProp, string | array $rbCols): void
    {
        $pkCol = $schema->columns[$pk];
        $withPk = array_values(array_filter($records, static fn (Record $r): bool => null !== $r->{$pkProp}));
        if (empty($withPk)) {
            return;
        }
        // Ascending-PK order to zip 1:1 with the `ORDER BY pk ASC` rows below (mirrors
        // upsertAllChunked()'s ordering assumption: PHP PK sort == the database's).
        /** @psalm-suppress MixedArgument */
        usort($withPk, static fn (Record $a, Record $b): int => $a->{$pkProp} <=> $b->{$pkProp});

        $bindBinaryAsLob = $dialect->bindsBinaryAsLob();
        $params = array_map(
            static fn (Record $r): mixed => ColumnSerializer::toParam($r->{$pkProp}, $pkCol, $bindBinaryAsLob),
            $withPk,
        );
        $qt = $dialect->quoteIdentifier($schema->tableName);
        $qpk = $dialect->quoteIdentifier($pk);
        $placeholders = implode(', ', array_fill(0, count($params), '?'));

        /** @psalm-suppress MixedArgumentTypeCoercion */
        $rows = $session->fetchAll("SELECT * FROM {$qt} WHERE {$qpk} IN ({$placeholders}) ORDER BY {$qpk} ASC", $params);

        foreach ($rows as $i => $row) {
            if (isset($withPk[$i])) {
                /** @psalm-suppress MixedArgument */
                if ('all' === $rbCols) {
                    $withPk[$i]->hydrateFromRow($row);
                } else {
                    $withPk[$i]->patchColumnsFromRow($row, $rbCols);
                }
            }
        }
    }

    /**
     * The columns a bulk INSERT of these records actually writes: non-auto-increment, non-generated,
     * non-ignored columns that carry a non-null value on at least one record (an all-null column is
     * dropped so its DB default fires). Key order follows the schema. Shared by
     * {@see buildBulkInsertSql()} and the auto read-back computation.
     *
     * @param list<Record>        $records
     * @param array<string, true> $ignore
     *
     * @return array<string, true>
     */
    private function bulkInsertWrittenColumns(array $records, TableSchema $schema, array $ignore): array
    {
        $candidates = array_filter(
            $schema->columns,
            fn ($col) => !$col->autoIncrement && !$col->isGenerated,
        );
        $candidates = array_diff_key($candidates, $ignore);

        $present = [];
        foreach ($candidates as $colName => $col) {
            foreach ($records as $record) {
                if (($record->{$col->propertyName} ?? null) !== null) {
                    $present[$colName] = true;
                    break;
                }
            }
        }

        return $present;
    }

    /**
     * Build a single multi-row `INSERT INTO … VALUES (…), (…)` covering every given record, writing
     * each record's PK column too (it is a normal, non-auto-increment column for application-minted
     * PKs). By default a duplicate-PK collision is left to surface as a DB error — the correct, loud
     * signal for the append-only tables {@see insertAll()} targets; with `$ignoreConflicts = true`
     * the statement carries the insert-or-ignore conflict clause instead, so a key collision skips
     * that row while the rest insert. Auto-increment and DB-generated columns are excluded (the DB
     * supplies them); a column is included when it is non-null on at least one record. Returns null
     * when the set is empty or no insertable column carries a value.
     *
     * Shared by {@see buildPlan()} (new/PK-null records) and {@see insertAll()} (all records).
     *
     * @param list<Record>        $records
     * @param array<string, true> $ignore          column names to drop from the INSERT (their DB default fires)
     * @param bool                $ignoreConflicts append the insert-or-ignore conflict clause (skip key collisions)
     */
    private function buildBulkInsertSql(array $records, TableSchema $schema, SqlDialect $dialect, array $ignore = [], bool $ignoreConflicts = false): ?string
    {
        if (empty($records)) {
            return null;
        }

        $presentCols = $this->bulkInsertWrittenColumns($records, $schema, $ignore);
        $colNames = array_keys($presentCols);

        if (empty($colNames)) {
            return null;
        }

        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($colNames as $colName) {
                $col = $schema->columns[$colName];
                $row[] = $dialect->toLiteral(
                    ColumnSerializer::toDbValue($record->{$col->propertyName} ?? null, $col),
                    $col,
                );
            }
            $rows[] = $row;
        }

        return $dialect->buildBulkInsert($schema->tableName, $colNames, $rows, $ignoreConflicts);
    }

    /**
     * Separate dirty records by PK presence and build the SQL plan for each group.
     *
     * @param list<Record>        $dirty
     * @param array<string, true> $ignore column names to drop from the write (INSERT default fires;
     *                                    UPDATE leaves the column untouched)
     *
     * @return array{insert: ?string, upsert: ?UpsertSql}
     */
    private function buildPlan(array $dirty, TableSchema $schema, SqlDialect $dialect, array $ignore = []): array
    {
        $pk = $schema->pk;
        $pkProp = $schema->pkProp;
        $noKeyRecords = array_values(array_filter($dirty, fn (Record $r) => null === $r->{$pkProp}));
        $keyedRecords = array_values(array_filter($dirty, fn (Record $r) => null !== $r->{$pkProp}));

        $upsert = null;

        // Plain INSERT for new (auto-increment PK) records — no upsert semantics needed
        $insert = $this->buildBulkInsertSql($noKeyRecords, $schema, $dialect, $ignore);

        // Deadlock-safe 3-step upsert for records with a known PK
        if (!empty($keyedRecords)) {
            // Membership (columns written): always the PK; plus a non-generated column that is
            // non-null on some record OR **dirty** on some record — the non-null clause keeps
            // NOT NULL columns in the INSERT step, the dirty clause lets an UPDATE clear a column
            // back to NULL. Generated columns are DB-computed — including one makes MySQL reject
            // the statement (error 1906) — so skip them, as save() and the plain-INSERT branch do.
            $presentCols = [$pk => true];
            $dirtyUnion = [];   // colName => true when dirty on at least one record
            $recordDirty = [];  // per keyed record (same iteration key): its dirtyFields() map
            foreach ($keyedRecords as $ri => $record) {
                $recordDirty[$ri] = $dirty = $record->dirtyFields();
                foreach ($schema->columns as $colName => $col) {
                    // Generated columns are DB-computed; ignored columns are caller-dropped. The PK
                    // is never dropped here (it seeds $presentCols above) so the upsert stays keyed.
                    if ($col->isGenerated || isset($ignore[$colName])) {
                        continue;
                    }
                    $isDirty = isset($dirty[$colName]);
                    if (($record->{$col->propertyName} ?? null) !== null || $isDirty) {
                        $presentCols[$colName] = true;
                    }
                    if ($isDirty) {
                        $dirtyUnion[$colName] = true;
                    }
                }
            }
            $colNames = array_values(array_filter(
                array_keys($schema->columns),
                fn ($n) => isset($presentCols[$n]),
            ));

            if (!empty($colNames)) {
                $rows = [];
                $rowDirty = [];  // aligned to $rows: the set of column names each row changed
                foreach ($keyedRecords as $ri => $record) {
                    $row = [];
                    $dirtySet = [];
                    foreach ($colNames as $colName) {
                        $col = $schema->columns[$colName];
                        $row[] = $dialect->toLiteral(
                            ColumnSerializer::toDbValue($record->{$col->propertyName} ?? null, $col),
                            $col,
                        );
                        if (isset($recordDirty[$ri][$colName])) {
                            $dirtySet[$colName] = true;
                        }
                    }
                    $rows[] = $row;
                    $rowDirty[] = $dirtySet;
                }
                // UPDATE only columns dirtied by at least one record (minus PK). buildUpsertSql()
                // then writes each column's WHEN only for the rows that changed it (ELSE keeps the
                // live value), so a heterogeneous batch of partial records updates each row's own
                // fields without clobbering a column a given row never supplied.
                $updateCols = array_values(array_filter(
                    $colNames,
                    fn ($n) => $n !== $pk && isset($dirtyUnion[$n]),
                ));
                $upsert = $dialect->buildUpsertSql($schema->tableName, $pk, $colNames, $rows, $updateCols, $rowDirty);
            }
        }

        return ['insert' => $insert, 'upsert' => $upsert];
    }

    /**
     * Delete all records in this set via a single DELETE … WHERE pk IN (…).
     *
     * @return int number of deleted rows
     */
    public function deleteAll(): int
    {
        if (empty($this->records)) {
            return 0;
        }

        $first = $this->records[0];
        if ($first instanceof AppendOnly) {
            throw AppendOnlyViolationException::forOperation($first::class, 'deleteAll()');
        }
        $schema = $first::schema();
        $pk = $schema->pk;
        $pkProp = $schema->pkProp;
        /** @psalm-suppress MixedReturnStatement */
        $ids = array_values(array_filter(
            array_map(fn (Record $r): mixed => $r->{$pkProp}, $this->records),
        ));

        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, \count($ids), '?'));
        $conn = $first::connection();
        $sql = 'DELETE FROM '.$conn->dialect->quoteIdentifier($schema->tableName)
            .' WHERE '.$conn->dialect->quoteIdentifier($pk)." IN ({$placeholders})";

        /** @psalm-suppress MixedArgumentTypeCoercion */
        return $conn->session->exec($sql, $ids);
    }

    // -----------------------------------------------------------------
    // Eager relation loading
    // -----------------------------------------------------------------

    /**
     * Imperatively load one or more relations (each a name or dot-notation chain) onto every record
     * in this set.
     *
     * Runs immediately against the already-loaded records — one IN(…) query per distinct relation
     * level, no JOINs, no N+1. Paths given in the same call share prefixes: `load('a.b', 'a.c')`
     * loads `a` once, then `b` and `c` off it. Relations are always (re)fetched; use
     * {@see loadMissing()} to skip records that already have a relation loaded.
     *
     * Examples:
     *   $orders->load('lines')                                          — one extra query
     *   $orders->load('lines.product')                                  — two (lines, then products)
     *   $orders->load('customer.billing', 'customer.shipping.country')  — customer loaded once
     *
     * @return static<T> fluent — returns $this after populating relation properties
     */
    public function load(string ...$relationPaths): static
    {
        $this->loadTree(self::buildPathTree($relationPaths), false);

        return $this;
    }

    /**
     * Like {@see load()}, but at each relation level only the records that don't already have it
     * loaded are queried — the skip-if-present counterpart.
     *
     * "Loaded" is tracked per record by the loader (see {@see Record::relationIsLoaded()}), so a
     * to-one relation that legitimately resolved to null is treated as loaded and left alone.
     * Multiple paths share prefixes within the call, exactly as {@see load()}.
     *
     * @return static<T> fluent
     */
    public function loadMissing(string ...$relationPaths): static
    {
        $this->loadTree(self::buildPathTree($relationPaths), true);

        return $this;
    }

    /**
     * Alias for {@see load()}.
     *
     * @deprecated since 0.4.0 — renamed to load() to match the "imperative post-load" semantics
     *             (Eloquent reserves with() for query-time eager loading). Will be removed at 1.0.
     *
     * @return static<T>
     */
    public function with(string ...$relationPaths): static
    {
        return $this->load(...$relationPaths);
    }

    /**
     * Parse dot-notation relation paths into a prefix trie, so segments shared across paths load
     * exactly once (`['a.b', 'a.c']` → `['a' => ['b' => [], 'c' => []]]`).
     *
     * @param array<array-key, string> $paths
     *
     * @return array<string, mixed> relName => nested subtree (recursively the same shape)
     */
    private static function buildPathTree(array $paths): array
    {
        $tree = [];
        foreach ($paths as $path) {
            $node = &$tree;
            foreach (explode('.', $path) as $segment) {
                if (!isset($node[$segment]) || !\is_array($node[$segment])) {
                    $node[$segment] = [];
                }
                /** @psalm-suppress MixedAssignment reference walk into the nested trie */
                $node = &$node[$segment];
            }
            unset($node);
        }

        /** @var array<string, mixed> $tree */
        return $tree;
    }

    /**
     * Walk a relation trie: load each relation at this level once, then descend into its subtree on
     * the related records. Shared prefixes are therefore loaded a single time.
     *
     * @param array<string, mixed> $tree relName => nested subtree (recursively the same shape)
     */
    private function loadTree(array $tree, bool $missingOnly): void
    {
        if (empty($this->records)) {
            return;
        }

        /** @psalm-suppress MixedAssignment iterating a recursively-typed trie (values are subtrees) */
        foreach ($tree as $relName => $subtree) {
            $this->loadOne($relName, $missingOnly);

            if (\is_array($subtree) && [] !== $subtree) {
                $related = $this->collectRelated($relName);
                if (!empty($related)) {
                    /** @var array<string, mixed> $subtree */
                    (new self($related))->loadTree($subtree, $missingOnly);
                }
            }
        }
    }

    /**
     * Load a single relation level onto this set (no chaining). With $missingOnly, only records
     * that don't already have the relation loaded are queried; the rest keep their value.
     */
    private function loadOne(string $relName, bool $missingOnly): void
    {
        if ($missingOnly) {
            $targets = array_values(array_filter(
                $this->records,
                static fn (Record $r): bool => !$r->relationIsLoaded($relName),
            ));
            if (!empty($targets)) {
                (new self($targets))->loadOne($relName, false);
            }

            return;
        }

        // Callers (loadTree, and the $missingOnly branch above) guarantee a non-empty set here.
        $first = $this->records[0];
        $schema = $first::schema();

        $relDef = $schema->relations[$relName]
            ?? throw new \InvalidArgumentException(
                \sprintf('Unknown relation "%s" on %s.', $relName, $first::class),
            );

        $localKey = $relDef->localKey ?? $schema->pk;
        $localProp = $schema->propFor($localKey);

        switch ($relDef->type) {
            case RelationType::OneToMany:
            case RelationType::OneToOneReversed:
                // FK is on the related table pointing back here
                /** @var class-string<Record> $targetClass */
                $targetClass = $relDef->targetClass;
                $fk = (string) $relDef->foreignKey;
                $targetSchema = TableSchema::fromClass($targetClass);
                $fkProp = $targetSchema->propFor($fk);
                $localValues = array_values(array_unique($this->pluck($localProp)));
                $placeholders = implode(', ', array_fill(0, \count($localValues), '?'));
                $qfk = $targetClass::connection()->dialect->quoteIdentifier($fk);
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $related = $targetClass::find("{$qfk} IN ({$placeholders})", $localValues);

                $grouped = $related->recordsGroupedByKey($fkProp);
                foreach ($this->records as $record) {
                    /** @psalm-suppress MixedArrayOffset */
                    $record->$relName = $grouped[$record->{$localProp}] ?? new self([]);
                }
                break;

            case RelationType::ManyToOne:
            case RelationType::OneToOne:
                // FK is on this table pointing to the related table's PK
                /** @var class-string<Record> $targetClass */
                $targetClass = $relDef->targetClass;
                $fk = (string) $relDef->foreignKey;
                $fkProp = $schema->propFor($fk);
                $fkValues = array_values(array_unique(array_filter($this->pluck($fkProp))));
                $targetSchema = TableSchema::fromClass($targetClass);
                $targetPk = $targetSchema->pk;
                $targetPkProp = $targetSchema->pkProp;
                $placeholders = implode(', ', array_fill(0, \count($fkValues), '?'));
                $qtpk = $targetClass::connection()->dialect->quoteIdentifier($targetPk);
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $related = $targetClass::find("{$qtpk} IN ({$placeholders})", $fkValues);

                $byPk = $related->recordsByKey($targetPkProp);
                foreach ($this->records as $record) {
                    /** @psalm-suppress MixedArrayOffset */
                    $record->$relName = $byPk[$record->{$fkProp}] ?? null;
                }
                break;

            case RelationType::MorphMany:
            case RelationType::MorphOne:
                $this->loadMorphParent($relName, $relDef, $localProp);
                break;

            case RelationType::MorphTo:
                $this->loadMorphChild($relName, $relDef);
                break;

            case RelationType::ManyToMany:
                $this->loadManyToMany($relName, $relDef, $localProp);
                break;

            case RelationType::HasManyThrough:
                $this->loadHasManyThrough($relName, $relDef, $localProp);
                break;
        }

        // Record that this relation is now loaded on every record in the set (so loadMissing()
        // can distinguish a loaded-but-null to-one from one never loaded).
        foreach ($this->records as $record) {
            $record->markRelationLoaded($relName);
        }
    }

    /**
     * Flatten the related records reachable via $relName across this whole set — a to-one yields
     * its single record, a to-many yields each of its records — for chained (dot-path) loading.
     *
     * @return list<Record>
     */
    private function collectRelated(string $relName): array
    {
        $allRelated = [];
        foreach ($this->records as $record) {
            /** @psalm-suppress MixedAssignment */
            $rel = $record->$relName;
            if ($rel instanceof self) {
                foreach ($rel->records as $r) {
                    $allRelated[] = $r;
                }
            } elseif ($rel instanceof Record) {
                $allRelated[] = $rel;
            }
        }

        return $allRelated;
    }

    // -----------------------------------------------------------------
    // Polymorphic relation loaders
    // -----------------------------------------------------------------

    /**
     * Load a MorphMany or MorphOne relation.
     *
     * The child table has two columns: a type discriminator (morphType) that holds a
     * constant value identifying the parent class, and a FK (morphKey) pointing to
     * the parent's PK. Both conditions are applied in a single IN(…) query.
     */
    private function loadMorphParent(
        string $relName,
        RelationDefinition $relDef,
        string $localProp,
    ): void {
        /** @var class-string<Record> $targetClass */
        $targetClass = $relDef->targetClass;
        $morphTypeCol = (string) $relDef->morphType;
        $morphKeyCol = (string) $relDef->morphKey;
        $morphValue = $relDef->morphValue;

        $targetSchema = TableSchema::fromClass($targetClass);
        $morphKeyProp = $targetSchema->propFor($morphKeyCol);

        $localValues = array_values(array_unique($this->pluck($localProp)));
        if (empty($localValues)) {
            $empty = RelationType::MorphOne === $relDef->type ? null : new self([]);
            foreach ($this->records as $record) {
                $record->$relName = $empty;
            }

            return;
        }

        $placeholders = implode(', ', array_fill(0, \count($localValues), '?'));
        $dialect = $targetClass::connection()->dialect;
        $qMorphType = $dialect->quoteIdentifier($morphTypeCol);
        $qMorphKey = $dialect->quoteIdentifier($morphKeyCol);
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $related = $targetClass::find(
            "{$qMorphType} = ? AND {$qMorphKey} IN ({$placeholders})",
            array_merge([$morphValue], $localValues),
        );

        if (RelationType::MorphOne === $relDef->type) {
            $grouped = $related->recordsGroupedByKey($morphKeyProp);
            foreach ($this->records as $record) {
                /** @psalm-suppress MixedArrayOffset */
                $record->$relName = ($grouped[$record->{$localProp}] ?? null)?->first();
            }
        } else {
            $grouped = $related->recordsGroupedByKey($morphKeyProp);
            foreach ($this->records as $record) {
                /** @psalm-suppress MixedArrayOffset */
                $record->$relName = $grouped[$record->{$localProp}] ?? new self([]);
            }
        }
    }

    /**
     * Load a MorphTo relation.
     *
     * The local record holds both a type discriminator column (morphType) and a FK column
     * (morphKey). Records are grouped by their discriminator value; one IN(…) query is
     * issued per distinct type present in the set.
     */
    private function loadMorphChild(string $relName, RelationDefinition $relDef): void
    {
        if (empty($this->records)) {
            return;
        }
        $localSchema = $this->records[0]::schema();
        $morphTypeCol = (string) $relDef->morphType;
        $morphKeyCol = (string) $relDef->morphKey;
        $morphTypeProp = $localSchema->propFor($morphTypeCol);
        $morphKeyProp = $localSchema->propFor($morphKeyCol);
        /** @var array<int|string, class-string<Record>> $morphMap */
        $morphMap = $relDef->morphMap ?? [];

        // Group local records by their type discriminator value
        /** @var array<int|string, list<Record>> $groups */
        $groups = [];
        foreach ($this->records as $record) {
            /** @psalm-suppress MixedAssignment */
            $typeVal = $record->{$morphTypeProp};
            // Records with no type set are skipped; the morphMap lookup below
            // handles unknown discriminator values gracefully.
            if (null === $typeVal) {
                continue;
            }
            /** @psalm-suppress MixedArrayOffset */
            $groups[$typeVal][] = $record;
        }

        foreach ($groups as $typeVal => $groupRecords) {
            $morphTargetClass = $morphMap[$typeVal] ?? null;
            if (null === $morphTargetClass) {
                continue; // Unknown discriminator — leave property null
            }

            /** @psalm-suppress MixedReturnStatement, MixedAssignment */
            $ids = array_values(array_unique(array_map(
                fn (Record $r): mixed => $r->{$morphKeyProp},
                $groupRecords,
            )));

            $targetSchema = TableSchema::fromClass($morphTargetClass);
            $targetPk = $targetSchema->pk;
            $targetPkProp = $targetSchema->pkProp;
            $placeholders = implode(', ', array_fill(0, \count($ids), '?'));
            $qtpk = $morphTargetClass::connection()->dialect->quoteIdentifier($targetPk);
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $related = $morphTargetClass::find("{$qtpk} IN ({$placeholders})", $ids);
            $byPk = $related->recordsByKey($targetPkProp);

            foreach ($groupRecords as $record) {
                /** @psalm-suppress MixedArrayOffset */
                $record->$relName = $byPk[$record->{$morphKeyProp}] ?? null;
            }
        }
    }

    /**
     * Load a ManyToMany relation: one query on the pivot table for the (local, target) key pairs,
     * then one query loading the target records by PK; group the targets back onto each local record.
     */
    private function loadManyToMany(string $relName, RelationDefinition $relDef, string $localProp): void
    {
        /** @var class-string<Record> $targetClass */
        $targetClass = $relDef->targetClass;

        $localValues = array_values(array_unique($this->pluck($localProp)));
        if (empty($localValues)) {
            foreach ($this->records as $record) {
                $record->$relName = new self([]);
            }

            return;
        }

        $dialect = $targetClass::connection()->dialect;
        $qLocal = $dialect->quoteIdentifier((string) $relDef->pivotLocalKey);
        $qForeign = $dialect->quoteIdentifier((string) $relDef->pivotForeignKey);
        $qPivot = $dialect->quoteIdentifier(Record::tablePrefix().(string) $relDef->pivotTable);
        $placeholders = implode(', ', array_fill(0, \count($localValues), '?'));
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $pivotRows = $targetClass::connection()->session->fetchAll(
            "SELECT {$qLocal}, {$qForeign} FROM {$qPivot} WHERE {$qLocal} IN ({$placeholders})",
            $localValues,
        );

        /** @var array<array-key, list<int|string>> $foreignByLocal */
        $foreignByLocal = [];
        /** @var array<array-key, int|string> $allForeign */
        $allForeign = [];
        foreach ($pivotRows as $row) {
            $lv = $row[(string) $relDef->pivotLocalKey] ?? null;
            $fv = $row[(string) $relDef->pivotForeignKey] ?? null;
            if (null === $lv || null === $fv) {
                continue;
            }
            /** @psalm-suppress InvalidArrayOffset */
            $foreignByLocal[$lv][] = $fv;
            /** @psalm-suppress InvalidArrayOffset */
            $allForeign[$fv] = $fv;
        }

        /** @var array<array-key, Record> $targetsByPk */
        $targetsByPk = [];
        if (!empty($allForeign)) {
            $targetSchema = TableSchema::fromClass($targetClass);
            $foreignValues = array_values($allForeign);
            $ph2 = implode(', ', array_fill(0, \count($foreignValues), '?'));
            $qtpk = $dialect->quoteIdentifier($targetSchema->pk);
            $targetsByPk = $targetClass::find("{$qtpk} IN ({$ph2})", $foreignValues)
                ->recordsByKey($targetSchema->pkProp);
        }

        foreach ($this->records as $record) {
            /**
             * @psalm-suppress MixedArrayOffset
             *
             * @var list<int|string> $keys
             */
            $keys = $foreignByLocal[$record->{$localProp}] ?? [];
            $related = [];
            foreach ($keys as $fk) {
                if (isset($targetsByPk[$fk])) {
                    $related[] = $targetsByPk[$fk];
                }
            }
            $record->$relName = new self($related);
        }
    }

    /**
     * Load a HasManyThrough relation: fetch the intermediate rows linking this set to the through
     * table, then the far records via secondKey; group the far records back onto each local record.
     */
    private function loadHasManyThrough(string $relName, RelationDefinition $relDef, string $localProp): void
    {
        /** @var class-string<Record> $farClass */
        $farClass = $relDef->targetClass;
        /** @var class-string<Record> $throughClass */
        $throughClass = $relDef->throughClass;

        $localValues = array_values(array_unique($this->pluck($localProp)));
        if (empty($localValues)) {
            foreach ($this->records as $record) {
                $record->$relName = new self([]);
            }

            return;
        }

        $throughSchema = TableSchema::fromClass($throughClass);
        $throughKeyProp = $throughSchema->propFor($relDef->throughKey ?? $throughSchema->pk);
        $fkProp = $throughSchema->propFor((string) $relDef->foreignKey);

        // Step 1: intermediate rows (through) whose foreignKey points at this set.
        $qFk = $throughClass::connection()->dialect->quoteIdentifier((string) $relDef->foreignKey);
        $ph = implode(', ', array_fill(0, \count($localValues), '?'));
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $throughRecords = $throughClass::find("{$qFk} IN ({$ph})", $localValues);

        /** @var array<array-key, int|string> $localByThroughKey  through-key value → local value */
        $localByThroughKey = [];
        /** @var array<array-key, int|string> $throughKeyValues */
        $throughKeyValues = [];
        foreach ($throughRecords as $tr) {
            /** @psalm-suppress MixedAssignment */
            $tk = $tr->{$throughKeyProp};
            /** @psalm-suppress MixedAssignment */
            $lv = $tr->{$fkProp};
            if (null === $tk || null === $lv) {
                continue;
            }
            /** @psalm-suppress MixedArrayOffset, MixedAssignment */
            $localByThroughKey[$tk] = $lv;
            /** @psalm-suppress MixedArrayOffset, MixedAssignment */
            $throughKeyValues[$tk] = $tk;
        }

        // Step 2: far records via secondKey IN (through key values); regroup by local value.
        /** @var array<array-key, list<Record>> $farByLocal */
        $farByLocal = [];
        if (!empty($throughKeyValues)) {
            $farSchema = TableSchema::fromClass($farClass);
            $secondKeyProp = $farSchema->propFor((string) $relDef->secondKey);
            $tkVals = array_values($throughKeyValues);
            $ph2 = implode(', ', array_fill(0, \count($tkVals), '?'));
            $qSecond = $farClass::connection()->dialect->quoteIdentifier((string) $relDef->secondKey);
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $farRecords = $farClass::find("{$qSecond} IN ({$ph2})", $tkVals);
            foreach ($farRecords as $fr) {
                /** @psalm-suppress MixedAssignment, MixedArrayOffset */
                $lv = $localByThroughKey[$fr->{$secondKeyProp}] ?? null;
                if (null === $lv) {
                    continue;
                }
                /** @psalm-suppress MixedArrayOffset, MixedArrayAssignment */
                $farByLocal[$lv][] = $fr;
            }
        }

        foreach ($this->records as $record) {
            /** @psalm-suppress MixedAssignment, MixedArrayOffset */
            $farByLocal[$record->{$localProp}] ??= [];
            /** @psalm-suppress MixedArrayOffset, MixedArgument */
            $record->$relName = new self($farByLocal[$record->{$localProp}]);
        }
    }

    // -----------------------------------------------------------------
    // Conversion helpers
    // -----------------------------------------------------------------

    /**
     * Return records as a list of raw-value arrays (column name → scalar|null).
     *
     * @return list<array<string, scalar|null>>
     */
    public function toArraySet(): array
    {
        return array_map(fn (Record $r) => $r->toRawArray(), $this->records);
    }

    // -----------------------------------------------------------------
    // Iterator, Countable, ArrayAccess
    // -----------------------------------------------------------------

    #[\Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * @return T
     */
    #[\Override]
    public function current(): mixed
    {
        return $this->records[$this->position];
    }

    #[\Override]
    public function key(): int
    {
        return $this->position;
    }

    #[\Override]
    public function next(): void
    {
        ++$this->position;
    }

    #[\Override]
    public function valid(): bool
    {
        return isset($this->records[$this->position]);
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->records);
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->records[$offset]);
    }

    /** @return T */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        $offset = match (true) {
            is_null($offset) => \count($this->records),
            is_int($offset)  => $offset,
            default          => throw new \InvalidArgumentException('Offset must be an integer or null.'),
        };
        if ($offset < 0) {
            throw new \InvalidArgumentException('Negative offsets are not supported.');
        }
        if ($offset >= \count($this->records)) {
            throw new \InvalidArgumentException('Offset out of bounds.');
        }

        return $this->records[$offset];
    }

    /**
     * @psalm-param mixed $offset
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $offset = match (true) {
            is_null($offset) => \count($this->records),
            is_int($offset)  => $offset,
            default          => throw new \InvalidArgumentException('Offset must be an integer or null.'),
        };
        if ($offset < 0) {
            throw new \InvalidArgumentException('Negative offsets are not supported.');
        }
        if ($offset > \count($this->records)) {
            throw new \InvalidArgumentException('Offsets cannot skip indices (no gaps allowed).');
        }

        /** @psalm-suppress PropertyTypeCoercion -- psalm disklikes offset set on a list */
        $this->records[$offset] = $value;
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        unset($this->records[$offset]);
        /** @psalm-suppress PropertyTypeCoercion */
        $this->records = array_values($this->records);
    }
}
