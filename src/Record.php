<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\Exception\RecordDeleteException;
use Nandan108\Attrecord\Exception\RecordNotFoundException;
use Nandan108\Attrecord\Exception\RecordSaveException;
use Nandan108\Attrecord\Schema\TableSchema;

/**
 * Base class for active-record entities.
 *
 * Subclasses declare their schema with PHP 8 attributes:
 *
 *   #[Table(name: 'my_table')]
 *   #[LockTier(1)]
 *   class MyEntity extends Record
 *   {
 *       #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
 *       public int $id;
 *
 *       #[Column(ColumnType::VarChar, length: 64)]
 *       public string $name;
 *   }
 *
 * Rules:
 *  - Column properties must be PUBLIC and must NOT be readonly.
 *  - Relation properties must be typed ?RecordSet and are never included in SQL.
 *  - Call Record::setConnection() once at bootstrap before any DB operation.
 */
abstract class Record
{
    /**
     * Raw DB values at the time of the last load/save (string|null per column).
     * Used for dirty detection.
     *
     * @var array<string, string|null>
     */
    private array $_snapshot = [];

    /** True for records that have never been persisted (no PK from DB yet). */
    private bool $_isNew = true;

    // -----------------------------------------------------------------
    // Connection management
    // -----------------------------------------------------------------

    private static ?Connection $_defaultConnection = null;

    /** @var array<class-string, Connection> */
    private static array $_classConnections = [];

    /**
     * Set the default Connection for all Record subclasses, or override for one class.
     *
     * @api
     *
     * @param class-string|null $forClass if non-null, override only for that class
     */
    public static function setConnection(Connection $connection, ?string $forClass = null): void
    {
        if (null !== $forClass) {
            self::$_classConnections[$forClass] = $connection;
        } else {
            self::$_defaultConnection = $connection;
        }
    }

    /** @api */
    public static function connection(): Connection
    {
        return self::$_classConnections[static::class]
            ?? self::$_defaultConnection
            ?? throw new AttrecordException(
                'No Connection configured. Call Record::setConnection() before any DB operation.',
            );
    }

    /** Shorthand: quote an identifier using the current class's connection dialect. */
    protected static function qi(string $identifier): string
    {
        return static::connection()->dialect->quoteIdentifier($identifier);
    }

    // -----------------------------------------------------------------
    // Schema access
    // -----------------------------------------------------------------

    /** @api */
    public static function schema(): TableSchema
    {
        return TableSchema::fromClass(static::class);
    }

    // -----------------------------------------------------------------
    // Static finders
    // -----------------------------------------------------------------

    /**
     * Load one record by PK, or return null if not found.
     *
     * @api
     *
     * @param bool $forUpdate issue SELECT … FOR UPDATE (must be inside transactional())
     */
    public static function getOne(
        int | string $id,
        bool $forUpdate = false,
        ?Transaction $tx = null,
    ): ?static {
        $schema = static::schema();
        $sql = self::buildSelectSql($schema->tableName, $schema->primaryKey, $forUpdate);
        $row = static::connection()->session->fetchOne($sql, [$id]);

        if (null === $row) {
            return null;
        }

        /** @psalm-suppress UnsafeInstantiation */
        $record = new static();
        $record->hydrateFromRow($row);

        if ($forUpdate && null !== $tx) {
            $tx->registerLock($record);
        }

        return $record;
    }

    /**
     * Load one record by PK, throw RecordNotFoundException if not found.
     *
     * @api
     */
    public static function getOneOrFail(int | string $id): static
    {
        return static::getOne($id)
            ?? throw new RecordNotFoundException(static::class, $id);
    }

    /**
     * Load one record by PK, or return a new (unsaved) instance pre-populated with that PK.
     *
     * @api
     */
    public static function getOneOrNew(int | string $id): static
    {
        $record = static::getOne($id);
        if (null !== $record) {
            return $record;
        }

        /** @psalm-suppress UnsafeInstantiation */
        $record = new static();
        $record->{static::schema()->primaryKey} = $id;

        return $record;
    }

    /**
     * Load all records matching a WHERE clause.
     *
     * @api
     *
     * @param string|WhereClause            $where        Optional WHERE clause (no "WHERE" keyword).
     *                                                    Accepts a raw SQL string (with ? or :named
     *                                                    placeholders) or a WhereClause builder instance.
     * @param array<array-key, scalar|null> $params       ignored when $where is a WhereClause
     * @param string                        $orderByLimit optional ORDER BY / LIMIT / OFFSET clause
     * @param bool                          $forUpdate    issue SELECT … FOR UPDATE
     *
     * @return RecordSet<static> a RecordSet of instances of the called class (never empty, even if no matches)
     */
    public static function find(
        string | WhereClause $where = '',
        array $params = [],
        string $orderByLimit = '',
        bool $forUpdate = false,
        ?Transaction $tx = null,
    ): RecordSet {
        if ($where instanceof WhereClause) {
            $params = $where->params();
            $where = $where->render(static::connection()->dialect);
        }

        ['sql' => $normSql, 'params' => $normParams] = NamedPlaceholderSql::positional(
            $where,
            $params,
        );

        $schema = static::schema();
        $whereClause = '' !== $normSql ? "WHERE {$normSql}" : '';

        $qt = static::qi($schema->tableName);
        if ($forUpdate) {
            // Deadlock prevention: always lock in ascending PK order
            $qpk = static::qi($schema->primaryKey);
            $sql = "SELECT * FROM {$qt} {$whereClause} ORDER BY {$qpk} ASC FOR UPDATE";
        } else {
            $sql = "SELECT * FROM {$qt} {$whereClause} {$orderByLimit}";
        }

        $rows = static::connection()->session->fetchAll($sql, $normParams);
        $records = [];

        foreach ($rows as $row) {
            /** @psalm-suppress UnsafeInstantiation */
            $record = new static();
            $record->hydrateFromRow($row);
            if ($forUpdate && null !== $tx) {
                $tx->registerLock($record);
            }
            $records[] = $record;
        }

        return new RecordSet($records);
    }

    /**
     * Load the first record matching a WHERE clause, or null.
     *
     * @api
     *
     * @param array<array-key, scalar|null> $params
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public static function findOne(string $where, array $params = []): ?static
    {
        return static::find($where, $params)->first();
    }

    /**
     * Convenience finder: single-column equality/comparison condition.
     *
     * Column name is quoted automatically using the class's configured dialect.
     *
     * @api
     *
     * @param scalar|null $value
     *
     * @return RecordSet<static>
     */
    public static function where(string $column, mixed $value, string $op = '='): RecordSet
    {
        return static::find(WhereClause::where($column, $value, $op));
    }

    /**
     * Convenience finder: IN-list condition — single or multi-column.
     *
     * Column names are quoted automatically.
     *
     * Single column:   whereIn('status', ['pending', 'confirmed'])
     * Multiple columns: whereIn(['status', 'type'], [['pending', 'order'], ['draft', 'quote']])
     *
     * @api
     *
     * @param string|list<string>                       $column single column name, or list of column names
     * @param list<scalar|null>|list<list<scalar|null>> $values flat list for single-column; list of rows for multi-column
     *
     * @return RecordSet<static>
     */
    public static function whereIn(string | array $column, array $values): RecordSet
    {
        if (\is_array($column)) {
            /** @var list<list<scalar|null>> $values */
            return static::whereInTuples($column, $values);
        }

        /** @var list<scalar|null> $values */
        return static::find(WhereClause::whereIn($column, $values));
    }

    /**
     * Convenience finder: multi-column IN condition using row-value constructors.
     *
     * Column names are quoted automatically. Useful for composite index seeks.
     * Supported by MySQL/MariaDB and PostgreSQL; not by SQLite.
     *
     * @api
     *
     * @param list<string>            $columns
     * @param list<list<scalar|null>> $rows
     *
     * @return RecordSet<static>
     */
    public static function whereInTuples(array $columns, array $rows): RecordSet
    {
        return static::find(WhereClause::whereInTuples($columns, $rows));
    }

    /**
     * @api
     *
     * @param array<array-key, scalar|null> $params
     */
    public static function countWhere(string $where, array $params = []): int
    {
        ['sql' => $normSql, 'params' => $normParams] = NamedPlaceholderSql::positional($where, $params);
        $schema = static::schema();
        $sql = 'SELECT COUNT(*) FROM '.static::qi($schema->tableName)." WHERE {$normSql}";

        return (int) static::connection()->session->fetchScalar($sql, $normParams);
    }

    /**
     * @api
     *
     * @param array<array-key, scalar|null> $params
     *
     * @return int Number of deleted rows
     */
    public static function deleteWhere(string $where, array $params = []): int
    {
        ['sql' => $normSql, 'params' => $normParams] = NamedPlaceholderSql::positional($where, $params);
        $schema = static::schema();
        $sql = 'DELETE FROM '.static::qi($schema->tableName)." WHERE {$normSql}";

        return static::connection()->session->exec($sql, $normParams);
    }

    // -----------------------------------------------------------------
    // Instance operations
    // -----------------------------------------------------------------

    /**
     * Persist this record.
     *
     * INSERT if new; UPDATE only dirty columns if existing.
     *
     * @api
     *
     * @param bool $force save all columns regardless of dirty state
     *
     * @return bool true = written to DB, false = nothing to save (record was clean)
     *
     * @throws RecordSaveException on DB error
     */
    public function save(bool $force = false): bool
    {
        $schema = static::schema();
        $conn = static::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;

        $colNames = [];
        $setParts = [];
        $params = [];

        foreach ($schema->columns as $name => $col) {
            if ($col->autoIncrement) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $value = $this->$name ?? null;

            if (!$force && !$this->_isNew) {
                $snapshot = ColumnSerializer::toSnapshotString($value, $col);
                if ($snapshot === ($this->_snapshot[$name] ?? null)) {
                    continue; // clean
                }
            }

            $qcol = $dialect->quoteIdentifier($name);
            $colNames[] = $qcol;
            $setParts[] = "{$qcol} = ?";
            $params[] = ColumnSerializer::toParam($value, $col);
        }

        if (empty($colNames)) {
            return false;
        }

        $qt = $dialect->quoteIdentifier($schema->tableName);
        $pk = $schema->primaryKey;
        $qpk = $dialect->quoteIdentifier($pk);

        try {
            if ($this->_isNew) {
                $cols = implode(', ', $colNames);
                $placeholders = implode(', ', array_fill(0, count($colNames), '?'));
                $insertSql = "INSERT INTO {$qt} ({$cols}) VALUES ({$placeholders})";
                $suffix = $dialect->insertReturningSuffix($qpk);
                if ('' !== $suffix) {
                    /** @psalm-suppress MixedArgumentTypeCoercion */
                    $row = $session->fetchOne("{$insertSql} {$suffix}", $params);
                    $this->$pk = $row[$pk]
                        ?? throw new RecordSaveException('INSERT did not return a generated key.');
                } else {
                    /** @psalm-suppress MixedArgumentTypeCoercion */
                    $session->exec($insertSql, $params);
                    $this->$pk = $session->lastInsertId();
                }
                $this->_isNew = false;
            } else {
                /** @psalm-suppress MixedAssignment */
                $params[] = $this->$pk;
                $sql = "UPDATE {$qt} SET ".implode(', ', $setParts)." WHERE {$qpk} = ?";
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $session->exec($sql, $params);
            }
        } catch (RecordSaveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        $this->refreshSnapshot($schema);

        return true;
    }

    /**
     * Delete this record from the database.
     *
     * @api
     *
     * @throws RecordDeleteException on DB error
     */
    public function delete(): void
    {
        $schema = static::schema();
        $pk = $schema->primaryKey;
        /** @psalm-suppress MixedAssignment */
        $pkVal = $this->$pk;

        if (null === $pkVal) {
            throw new RecordDeleteException('Cannot delete a record with no primary key value.');
        }

        try {
            $conn = static::connection();
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $conn->session->exec(
                'DELETE FROM '.$conn->dialect->quoteIdentifier($schema->tableName)
                    .' WHERE '.$conn->dialect->quoteIdentifier($pk).' = ?',
                [$pkVal],
            );
        } catch (\Throwable $e) {
            throw new RecordDeleteException($e->getMessage(), $e);
        }

        $this->_snapshot = [];
        $this->_isNew = true;
    }

    /**
     * Re-fetch this record from the database, refreshing all properties and snapshot.
     *
     * @api
     *
     * @throws RecordNotFoundException if the record no longer exists
     */
    public function reload(): void
    {
        $schema = static::schema();
        $pk = $schema->primaryKey;
        /** @psalm-suppress MixedAssignment */
        $pkVal = $this->$pk;

        $sql = self::buildSelectSql($schema->tableName, $pk, false);
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $row = static::connection()->session->fetchOne($sql, [$pkVal]);

        if (null === $row) {
            /** @psalm-suppress MixedArgument */
            throw new RecordNotFoundException(static::class, $pkVal);
        }

        $this->hydrateFromRow($row);
    }

    // -----------------------------------------------------------------
    // Dirty tracking
    // -----------------------------------------------------------------

    /**
     * Return true if any (or specific) column(s) differ from the last DB state.
     *
     * @api
     *
     * @param string ...$fields If omitted, checks all columns.
     */
    public function isDirty(string ...$fields): bool
    {
        return !empty($this->dirtyFields(...$fields));
    }

    /**
     * Return a map of dirty columns: column name → [snapshotValue, currentValue].
     *
     * @api
     *
     * @param string ...$fields If omitted, checks all columns.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public function dirtyFields(string ...$fields): array
    {
        $schema = static::schema();
        $check = empty($fields) ? array_keys($schema->columns) : $fields;
        $dirty = [];

        foreach ($check as $name) {
            $col = $schema->columns[$name];
            /** @psalm-suppress MixedAssignment */
            $current = ColumnSerializer::toSnapshotString($this->$name ?? null, $col);
            $snapshot = $this->_snapshot[$name] ?? null;

            if ($current !== $snapshot) {
                $dirty[$name] = [$snapshot, $current];
            }
        }

        return $dirty;
    }

    /** @api */
    public function isNew(): bool
    {
        return $this->_isNew;
    }

    // -----------------------------------------------------------------
    // Transactional helper (static shortcut)
    // -----------------------------------------------------------------

    /**
     * Execute a closure inside a DB transaction with a Transaction scope object.
     *
     * @api
     *
     * @template TResult
     *
     * @param \Closure(Transaction): TResult $operation
     *
     * @return TResult
     */
    public static function transactional(\Closure $operation): mixed
    {
        $tx = Transaction::push();
        try {
            return static::connection()->session->transactional(fn () => $operation($tx));
        } finally {
            Transaction::pop();
        }
    }

    // -----------------------------------------------------------------
    // Hydration (also used by RecordSet and LockSet)
    // -----------------------------------------------------------------

    /**
     * Populate this instance from a raw DB row and mark it as clean.
     *
     * @param array<string, scalar|null> $row
     *
     * @internal used by finders, LockSet, and hydrateFromArray()
     */
    public function hydrateFromRow(array $row): void
    {
        $schema = static::schema();

        foreach ($schema->columns as $name => $col) {
            $raw = $row[$name] ?? null;
            $this->_snapshot[$name] = null !== $raw ? (string) $raw : null;
            $this->$name = ColumnSerializer::fromDb($raw, $col);
        }

        $this->_isNew = false;
    }

    /**
     * Simulate a DB-loaded record for test purposes.
     *
     * Sets both live properties and the internal snapshot so the record appears clean.
     * Do NOT use in production code — this bypasses all validation that occurs during a
     * real DB load.
     *
     * @api
     *
     * @internal
     *
     * @param array<string, scalar|null> $data
     *
     * @psalm-suppress UnsafeInstantiation
     */
    public static function hydrateFromArray(array $data): static
    {
        $record = new static();
        $record->hydrateFromRow($data);

        return $record;
    }

    // -----------------------------------------------------------------
    // Snapshot helpers (also used by RecordSet)
    // -----------------------------------------------------------------

    /**
     * Mark this record as clean — updates the snapshot from current property values.
     * Called after RecordSet::saveAll() so dirty tracking reflects the written state.
     *
     * @internal
     */
    public function markClean(): void
    {
        $this->refreshSnapshot(static::schema());
        $this->_isNew = false;
    }

    /**
     * Return current column values as a raw-string array (snapshot format).
     * Useful for serialisation; does NOT include relation properties.
     *
     * @api
     *
     * @return array<string, scalar|null>
     */
    public function toRawArray(): array
    {
        $schema = static::schema();
        $out = [];
        foreach ($schema->columns as $name => $col) {
            /** @psalm-suppress MixedAssignment */
            $out[$name] = ColumnSerializer::toParam($this->$name ?? null, $col);
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    private static function buildSelectSql(
        string $table,
        string $pk,
        bool $forUpdate,
    ): string {
        $dialect = static::connection()->dialect;
        $qt = $dialect->quoteIdentifier($table);
        $qpk = $dialect->quoteIdentifier($pk);
        $orderPart = $forUpdate ? "ORDER BY {$qpk} ASC " : '';
        $forUpdatePart = $forUpdate ? 'FOR UPDATE' : '';

        return "SELECT * FROM {$qt} WHERE {$qpk} = ? {$orderPart}{$forUpdatePart}";
    }

    private function refreshSnapshot(TableSchema $schema): void
    {
        foreach ($schema->columns as $name => $col) {
            $this->_snapshot[$name] = ColumnSerializer::toSnapshotString($this->$name ?? null, $col);
        }
    }
}
