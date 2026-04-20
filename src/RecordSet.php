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
     * Return all values of one column as a flat array.
     *
     * @return list<mixed>
     */
    public function pluck(string $field): array
    {
        /** @psalm-suppress MixedReturnStatement */
        return array_map(fn (Record $r): mixed => $r->$field, $this->records);
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
     * Group records by a (possibly non-unique) column value.
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
     * Group records by multiple keys into a nested associative array.
     *
     * @return array<int|string, mixed>
     */
    public function recordsGroupedByKeys(string ...$fields): array
    {
        $out = [];
        foreach ($this->records as $r) {
            /** @psalm-suppress MixedAssignment */
            $bucket = &$out;
            foreach ($fields as $f) {
                /** @psalm-suppress MixedAssignment, MixedArrayOffset, MixedArrayAccess, MixedArrayAssignment, UnsupportedReferenceUsage */
                $bucket = &$bucket[$r->$f];
            }
            /** @psalm-suppress PossiblyUndefinedMethod, MixedArrayAssignment */
            $bucket[] = $r;
            unset($bucket);
        }

        /** @var array<int|string, mixed> $out */
        return $out;
    }

    // -----------------------------------------------------------------
    // Bulk write operations
    // -----------------------------------------------------------------

    /**
     * Save all dirty records in a single round-trip using the bulk-upsert pattern:
     *
     *   INSERT INTO t (col1, col2)
     *   SELECT col1, col2 FROM (
     *       SELECT val1 AS col1, val2 AS col2
     *       UNION ALL SELECT val3, val4
     *   ) vals
     *   ON DUPLICATE KEY UPDATE col1=vals.col1, col2=vals.col2
     *
     * Notes:
     *  - Clean (unchanged) records are skipped automatically.
     *  - New records (no PK) are included in the INSERT, but their auto-generated PKs are
     *    NOT assigned back to the PHP objects. Use Record::save() when you need the new PK.
     *  - After a successful call, all dirty records are marked clean.
     *
     * @return bool true = SQL executed, false = nothing to save (all records were clean)
     *
     * @throws RecordSaveException on DB error
     */
    public function saveAll(bool $force = false): bool
    {
        if (empty($this->records)) {
            return false;
        }

        $first = $this->records[0];
        $schema = $first::schema();
        $conn = $first::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;
        $pk = $schema->primaryKey;

        // Filter to dirty records
        $dirty = $force
            ? $this->records
            : array_filter($this->records, fn (Record $r) => $r->isDirty());
        $dirty = array_values($dirty);

        if (empty($dirty)) {
            return false;
        }

        // Columns to include: exclude autoIncrement; exclude columns that are null
        // in every dirty record (no data to send for those columns)
        $candidates = array_keys(array_filter(
            $schema->columns,
            fn ($col) => !$col->autoIncrement,
        ));

        $presentCols = [];
        foreach ($dirty as $record) {
            foreach ($candidates as $name) {
                if (($record->$name ?? null) !== null) {
                    $presentCols[$name] = true;
                }
            }
        }
        $colNames = array_values(array_filter($candidates, fn ($n) => isset($presentCols[$n])));

        if (empty($colNames)) {
            return false;
        }

        // Build one row of literals per dirty record
        $rows = [];
        foreach ($dirty as $record) {
            $rowLiterals = [];
            foreach ($colNames as $name) {
                $rowLiterals[] = $dialect->toLiteral($record->$name ?? null, $schema->columns[$name]);
            }
            $rows[] = $rowLiterals;
        }

        // Columns updated on conflict (exclude PK)
        $updateCols = array_values(array_filter($colNames, fn ($n) => $n !== $pk));

        $sql = $dialect->buildBulkUpsert(
            tableName: $schema->tableName,
            columnNames: $colNames,
            pkColumnNames: [$pk],
            rows: $rows,
            updateColumns: $updateCols,
        );

        try {
            $session->exec($sql);
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        // Mark all dirty records as clean
        foreach ($dirty as $record) {
            $record->markClean();
        }

        return true;
    }

    /**
     * Return the SQL that saveAll() would execute, without touching the DB.
     * Useful for test assertions.
     *
     * @return string|null null if nothing to save (all records clean)
     */
    public function buildSaveAllSql(): ?string
    {
        // TODO: extract SQL-building logic from saveAll() into a shared private method
        // so this can call it without executing. Deferred to a follow-up refactor.
        throw new \BadMethodCallException('buildSaveAllSql() is not yet implemented.');
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
        $sql = "DELETE FROM `{$schema->tableName}` WHERE `{$pk}` IN ({$placeholders})";

        /** @psalm-suppress MixedArgumentTypeCoercion */
        return $first::connection()->session->exec($sql, $ids);
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
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $related = $targetClass::find("`{$fk}` IN ({$placeholders})", $localValues);

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
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $related = $targetClass::find("`{$targetPk}` IN ({$placeholders})", $fkValues);

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
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $related = $targetClass::find(
            "`{$morphTypeCol}` = ? AND `{$morphKeyCol}` IN ({$placeholders})",
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
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $related = $morphTargetClass::find("`{$targetPk}` IN ({$placeholders})", $ids);
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
