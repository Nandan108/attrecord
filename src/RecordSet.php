<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Enum\RelationType;
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
            $pk = $this->records[0]::schema()->primaryKey;
            $out = [];
            foreach ($this->records as $r) {
                /** @psalm-suppress MixedArrayOffset, MixedAssignment */
                $out[$r->$pk] = $this->extractFields($r, $fields);
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
        $pk = $schema->primaryKey;

        $dirtyRecords = $force
            ? $this->records
            : array_filter($this->records, fn (Record $r) => $r->isDirty());

        if (empty($dirtyRecords)) {
            return null;
        }

        $dirtyRecords = array_values($dirtyRecords);

        $plan = $this->buildPlan($dirtyRecords, $pk, $conn->dialect, $schema);

        if (null === $plan['insert'] && null === $plan['upsert']) {
            return null;
        }

        $session = $conn->session;
        $qpk = $conn->dialect->quoteIdentifier($pk);
        $returningSuffix = $conn->dialect->insertReturningSuffix($qpk);

        try {
            $counts = $session->transactional(
                function () use ($session, $plan, $pk, $returningSuffix): array {
                    $inserted = 0;
                    $updated = 0;
                    $insertedIds = [];

                    if (null !== $plan['insert']) {
                        if ('' !== $returningSuffix) {
                            // PostgreSQL: RETURNING gives back the generated PKs directly
                            $rows = $session->fetchAll($plan['insert']."\n".$returningSuffix);
                            foreach ($rows as $row) {
                                /** @psalm-suppress MixedAssignment */
                                $insertedIds[] = $row[$pk];
                            }
                            $inserted += \count($rows);
                        } else {
                            // MySQL/MariaDB: lastInsertId() is the first ID; range is sequential
                            $n = $session->exec($plan['insert']);
                            if ($n > 0) {
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

        foreach ($dirtyRecords as $record) {
            $record->markClean();
        }

        /** @var list<int|string> $insertedIds */
        $insertedIds = $counts['insertedIds'];

        return new SaveResult($counts['inserted'], $counts['updated'], $insertedIds);
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
        $pk = $schema->primaryKey;

        $dirty = $force
            ? $this->records
            : array_filter($this->records, fn (Record $r) => $r->isDirty());
        $dirty = array_values($dirty);

        if (empty($dirty)) {
            return null;
        }

        $plan = $this->buildPlan($dirty, $pk, $conn->dialect, $schema);

        return $plan['upsert'];
    }

    /**
     * Separate dirty records by PK presence and build the SQL plan for each group.
     *
     * @param list<Record> $dirty
     *
     * @return array{insert: ?string, upsert: ?UpsertSql}
     */
    private function buildPlan(array $dirty, string $pk, SqlDialect $dialect, TableSchema $schema): array
    {
        $noKeyRecords = array_values(array_filter($dirty, fn (Record $r) => null === $r->$pk));
        $keyedRecords = array_values(array_filter($dirty, fn (Record $r) => null !== $r->$pk));

        $insert = null;
        $upsert = null;

        // Plain INSERT for new (auto-increment PK) records — no upsert semantics needed
        if (!empty($noKeyRecords)) {
            $candidates = array_keys(array_filter(
                $schema->columns,
                fn ($col) => !$col->autoIncrement,
            ));

            $presentCols = [];
            foreach ($noKeyRecords as $record) {
                foreach ($candidates as $name) {
                    if (($record->$name ?? null) !== null) {
                        $presentCols[$name] = true;
                    }
                }
            }
            $colNames = array_values(array_filter($candidates, fn ($n) => isset($presentCols[$n])));

            if (!empty($colNames)) {
                $rows = [];
                foreach ($noKeyRecords as $record) {
                    $row = [];
                    foreach ($colNames as $name) {
                        $row[] = $dialect->toLiteral($record->$name ?? null, $schema->columns[$name]);
                    }
                    $rows[] = $row;
                }
                $insert = $dialect->buildBulkInsert($schema->tableName, $colNames, $rows);
            }
        }

        // Deadlock-safe 3-step upsert for records with a known PK
        if (!empty($keyedRecords)) {
            $candidates = array_keys($schema->columns);

            // Always include PK; include other columns present in at least one record
            $presentCols = [$pk => true];
            foreach ($keyedRecords as $record) {
                foreach ($candidates as $name) {
                    if (($record->$name ?? null) !== null) {
                        $presentCols[$name] = true;
                    }
                }
            }
            $colNames = array_values(array_filter($candidates, fn ($n) => isset($presentCols[$n])));

            if (!empty($colNames)) {
                $rows = [];
                foreach ($keyedRecords as $record) {
                    $row = [];
                    foreach ($colNames as $name) {
                        $row[] = $dialect->toLiteral($record->$name ?? null, $schema->columns[$name]);
                    }
                    $rows[] = $row;
                }
                $updateCols = array_values(array_filter($colNames, fn ($n) => $n !== $pk));
                $upsert = $dialect->buildUpsertSql($schema->tableName, $pk, $colNames, $rows, $updateCols);
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
        $pk = $schema->primaryKey;
        /** @psalm-suppress MixedReturnStatement */
        $ids = array_values(array_filter(
            array_map(fn (Record $r): mixed => $r->$pk, $this->records),
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

        $localKey = $relDef->localKey ?? $schema->primaryKey;

        switch ($relDef->type) {
            case RelationType::OneToMany:
            case RelationType::OneToOneReversed:
                // FK is on the related table pointing back here
                /** @var class-string<Record> $targetClass */
                $targetClass = $relDef->targetClass;
                $fk = (string) $relDef->foreignKey;
                $localValues = array_values(array_unique($this->pluck($localKey)));
                $placeholders = implode(', ', array_fill(0, \count($localValues), '?'));
                $qfk = $targetClass::connection()->dialect->quoteIdentifier($fk);
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $related = $targetClass::find("{$qfk} IN ({$placeholders})", $localValues);

                $grouped = $related->recordsGroupedByKey($fk);
                foreach ($this->records as $record) {
                    /** @psalm-suppress MixedArrayOffset */
                    $record->$relName = $grouped[$record->$localKey] ?? new self([]);
                }
                break;

            case RelationType::ManyToOne:
            case RelationType::OneToOne:
                // FK is on this table pointing to the related table's PK
                /** @var class-string<Record> $targetClass */
                $targetClass = $relDef->targetClass;
                $fk = (string) $relDef->foreignKey;
                $fkValues = array_values(array_unique(array_filter($this->pluck($fk))));
                $targetSchema = TableSchema::fromClass($targetClass);
                $targetPk = $targetSchema->primaryKey;
                $placeholders = implode(', ', array_fill(0, \count($fkValues), '?'));
                $qtpk = $targetClass::connection()->dialect->quoteIdentifier($targetPk);
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $related = $targetClass::find("{$qtpk} IN ({$placeholders})", $fkValues);

                $byPk = $related->recordsByKey($targetPk);
                foreach ($this->records as $record) {
                    /** @psalm-suppress MixedArrayOffset */
                    $record->$relName = $byPk[$record->$fk] ?? null;
                }
                break;

            case RelationType::MorphMany:
            case RelationType::MorphOne:
                $this->loadMorphParent($relName, $relDef, $localKey);
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
        string $localKey,
    ): void {
        /** @var class-string<Record> $targetClass */
        $targetClass = $relDef->targetClass;
        $morphTypeCol = (string) $relDef->morphType;
        $morphKeyCol = (string) $relDef->morphKey;
        $morphValue = $relDef->morphValue;

        $localValues = array_values(array_unique($this->pluck($localKey)));
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
            $grouped = $related->recordsGroupedByKey($morphKeyCol);
            foreach ($this->records as $record) {
                /** @psalm-suppress MixedArrayOffset */
                $record->$relName = ($grouped[$record->$localKey] ?? null)?->first();
            }
        } else {
            $grouped = $related->recordsGroupedByKey($morphKeyCol);
            foreach ($this->records as $record) {
                /** @psalm-suppress MixedArrayOffset */
                $record->$relName = $grouped[$record->$localKey] ?? new self([]);
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
        $morphTypeCol = (string) $relDef->morphType;
        $morphKeyCol = (string) $relDef->morphKey;
        /** @var array<int|string, class-string<Record>> $morphMap */
        $morphMap = $relDef->morphMap ?? [];

        // Group local records by their type discriminator value
        /** @var array<int|string, list<Record>> $groups */
        $groups = [];
        foreach ($this->records as $record) {
            /** @psalm-suppress MixedAssignment */
            $typeVal = $record->$morphTypeCol;
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
                fn (Record $r): mixed => $r->$morphKeyCol,
                $groupRecords,
            )));

            $targetPk = TableSchema::fromClass($morphTargetClass)->primaryKey;
            $placeholders = implode(', ', array_fill(0, \count($ids), '?'));
            $qtpk = $morphTargetClass::connection()->dialect->quoteIdentifier($targetPk);
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $related = $morphTargetClass::find("{$qtpk} IN ({$placeholders})", $ids);
            $byPk = $related->recordsByKey($targetPk);

            foreach ($groupRecords as $record) {
                /** @psalm-suppress MixedArrayOffset */
                $record->$relName = $byPk[$record->$morphKeyCol] ?? null;
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
