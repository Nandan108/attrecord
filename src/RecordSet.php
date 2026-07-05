<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\Exception\RecordSaveException;
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
     * across all records before a saveAll() call.
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
     * Save all dirty records using a deadlock-safe bulk strategy:
     *
     *  - New records (PK null): plain INSERT INTO … VALUES (…), (…)
     *  - Keyed records (PK set): 3-step pattern inside a transaction —
     *      1. INSERT IGNORE  — inserts truly new rows, skips existing
     *      2. SELECT pk … ORDER BY pk ASC FOR UPDATE  — deterministic lock order
     *      3. UPDATE … SET col = CASE pk WHEN … END  — applies changes
     *
     * Notes:
     *  - Clean (unchanged) records are skipped automatically.
     *  - New records' auto-generated PKs are NOT assigned back to the PHP objects.
     *    Use Record::save() when you need the new PK.
     *  - After a successful call, all dirty records are marked clean.
     *
     * @param bool $force If true, save all records regardless of dirty state (useful for testing);
     *
     * @return SaveResult|null SaveResult with inserted/updated counts, or null if nothing to save
     *
     * @throws RecordSaveException on DB error
     */
    public function saveAll(bool $force = false): ?SaveResult
    {
        if (empty($this->records)) {
            return null;
        }

        $first = $this->records[0];
        $schema = $first::schema();
        $conn = $first::connection();
        $pk = $schema->pk;

        $dirtyRecords = $force
            ? $this->records
            : array_filter($this->records, fn (Record $r) => $r->isDirty());

        if (empty($dirtyRecords)) {
            return null;
        }

        foreach ($dirtyRecords as $r) {
            $r->beforeSave();
            $r->validate();
        }

        $dirtyRecords = array_values($dirtyRecords);

        // New (auto-increment) records in the exact order buildPlan() will INSERT them, so
        // the DB-generated ids can be written back onto the right objects afterwards.
        $pkProp = $schema->pkProp;
        /** @var list<Record> $insertedRecords */
        $insertedRecords = array_values(array_filter(
            $dirtyRecords,
            static fn (Record $r): bool => null === $r->{$pkProp},
        ));

        $plan = $this->buildPlan($dirtyRecords, $schema, $conn->dialect);

        if (null === $plan['insert'] && null === $plan['upsert']) {
            return null;
        }

        $session = $conn->session;
        $qpk = $conn->dialect->quoteIdentifier($pk);
        $returningSuffix = $conn->dialect->insertReturningSuffix($qpk);

        $pkColumn = $schema->columns[$pk];
        $pkAutoIncrement = $pkColumn->autoIncrement;

        try {
            $counts = $session->transactional(
                function () use ($session, $plan, $pk, $pkColumn, $returningSuffix, $pkAutoIncrement): array {
                    $inserted = 0;
                    $updated = 0;
                    $insertedIds = [];

                    if (null !== $plan['insert']) {
                        if ('' !== $returningSuffix) {
                            // PostgreSQL: RETURNING gives back the generated PKs directly. Cast
                            // each through fromDb (PG returns bigint as a string) so insertedIds
                            // and the back-filled record PKs are ints, matching the MySQL path.
                            $rows = $session->fetchAll($plan['insert']."\n".$returningSuffix);
                            foreach ($rows as $row) {
                                /** @psalm-suppress MixedAssignment, MixedArgument */
                                $insertedIds[] = ColumnSerializer::fromDb($row[$pk], $pkColumn, $row);
                            }
                            $inserted += \count($rows);
                        } else {
                            // MySQL/MariaDB: lastInsertId() is the first ID; range is sequential.
                            // Only meaningful for auto-increment PKs — for application-minted
                            // PKs (e.g. BINARY(16) UUIDs) the caller already knows the IDs,
                            // so leave $insertedIds empty.
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
                },
            );
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        /** @var list<int|string> $insertedIds */
        $insertedIds = $counts['insertedIds'];

        // Back-fill DB-generated auto-increment ids onto the new records (mirrors save()),
        // in INSERT order, BEFORE markClean() so the refreshed snapshot includes the id.
        // Application-minted PKs (e.g. BINARY(16) UUIDs) yield no insertedIds, so this is a
        // no-op for them — the caller already set those PKs.
        if ($pkAutoIncrement) {
            foreach ($insertedRecords as $index => $record) {
                if (array_key_exists($index, $insertedIds)) {
                    $record->{$pkProp} = $insertedIds[$index];
                }
            }
        }

        foreach ($dirtyRecords as $record) {
            $record->markClean();
        }

        return new SaveResult($counts['inserted'], $counts['updated'], $insertedIds);
    }

    /**
     * Bulk upsert keyed by a declared #[UniqueKey], **without burning auto-increment ids** on
     * the rows that already exist.
     *
     * One `SELECT … WHERE (conflict cols) IN (…)` resolves the PKs of existing rows; those
     * records get their PK set so {@see saveAll()} routes them through its keyed-upsert (which
     * supplies the PK, so MySQL allocates no auto-increment value), while genuinely-new records
     * (PK still null) go through saveAll's plain bulk INSERT (one AI value each, none wasted).
     * Net: the loop-free, burn-free counterpart of an `INSERT … ON DUPLICATE KEY UPDATE` batch.
     *
     * Records that already carry a PK are left untouched (already keyed). The SELECT-then-write
     * is not atomic — a concurrent insert of the same conflict tuple would fall back to
     * saveAll's INSERT-IGNORE path (one burned id) — acceptable for the low-concurrency
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
        $schema = $first::schema();

        $conflictCols = $schema->uniqueKeys[$conflictKey]
            ?? throw new AttrecordException(
                sprintf('upsertAllByUniqueKey: unknown unique key "%s" on %s.', $conflictKey, $first::class),
            );

        $this->resolveExistingPksByUniqueKey($schema, $conflictCols);

        return $this->saveAll();
    }

    /**
     * For each PK-less record whose conflict-key columns are all set, look up the existing
     * row's PK (one batched `IN` query) and assign it, so a subsequent {@see saveAll()} updates
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
                continue; // already keyed → saveAll handles it directly
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
     * Return the upsert plan that saveAll() would execute for keyed (known-PK) records,
     * without touching the DB. Returns null if there are no dirty keyed records.
     *
     * Useful for test assertions on the generated SQL.
     * New records (PK null) produce a plain INSERT executed separately by saveAll();
     * use CapturingDbSession + saveAll() to inspect that statement.
     */
    public function buildSaveAllSql(bool $force = false): ?UpsertSql
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

        $plan = $this->buildPlan($dirty, $schema, $conn->dialect);

        return $plan['upsert'];
    }

    /**
     * Separate dirty records by PK presence and build the SQL plan for each group.
     *
     * @param list<Record> $dirty
     *
     * @return array{insert: ?string, upsert: ?UpsertSql}
     */
    private function buildPlan(array $dirty, TableSchema $schema, SqlDialect $dialect): array
    {
        $pk = $schema->pk;
        $pkProp = $schema->pkProp;
        $noKeyRecords = array_values(array_filter($dirty, fn (Record $r) => null === $r->{$pkProp}));
        $keyedRecords = array_values(array_filter($dirty, fn (Record $r) => null !== $r->{$pkProp}));

        $insert = null;
        $upsert = null;

        // Plain INSERT for new (auto-increment PK) records — no upsert semantics needed
        if (!empty($noKeyRecords)) {
            $candidates = array_filter(
                $schema->columns,
                fn ($col) => !$col->autoIncrement && !$col->isGenerated,
            );

            $presentCols = [];
            foreach ($noKeyRecords as $record) {
                foreach ($candidates as $colName => $col) {
                    if (($record->{$col->propertyName} ?? null) !== null) {
                        $presentCols[$colName] = true;
                    }
                }
            }
            $colNames = array_values(array_filter(
                array_keys($candidates),
                fn ($n) => isset($presentCols[$n]),
            ));

            if (!empty($colNames)) {
                $rows = [];
                foreach ($noKeyRecords as $record) {
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
                $insert = $dialect->buildBulkInsert($schema->tableName, $colNames, $rows);
            }
        }

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
                    if ($col->isGenerated) {
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
     * Load one relation (or a dot-notation chain) for all records in this set.
     *
     * Examples:
     *   $orders->with('lines')           — one extra query
     *   $orders->with('lines.product')   — two extra queries (lines, then products)
     *
     * Each level issues one IN(…) query — no JOINs, no N+1 queries.
     *
     * @return static<T> fluent — returns $this after populating relation properties
     */
    public function with(string $relationPath): static
    {
        if (empty($this->records)) {
            return $this;
        }

        $parts = explode('.', $relationPath, 2);
        $relName = $parts[0];
        $nestedPath = $parts[1] ?? null;

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
        }

        // Recurse for dot-notation chains
        if (null !== $nestedPath) {
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
            if (!empty($allRelated)) {
                (new self($allRelated))->with($nestedPath);
            }
        }

        return $this;
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
