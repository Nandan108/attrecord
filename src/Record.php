<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Exception\AppendOnlyViolationException;
use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\Exception\OptimisticLockException;
use Nandan108\Attrecord\Exception\RecordDeleteException;
use Nandan108\Attrecord\Exception\RecordNotFoundException;
use Nandan108\Attrecord\Exception\RecordSaveException;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Exception\SchemaException;
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

    /**
     * Names of relations that have been *loaded* onto this record (populated by
     * {@see RecordSet::load()}), so {@see RecordSet::loadMissing()} can tell an
     * already-loaded-but-null to-one relation apart from one never loaded.
     *
     * @var array<string, true>
     */
    private array $_loadedRelations = [];

    /**
     * Whether the last save() call wrote to the database.
     *
     * true  — an INSERT or UPDATE was issued.
     * false — the record was clean; nothing was sent to the database.
     * null  — save() has not been called yet on this instance.
     *
     * @api
     */
    public ?bool $_saved = null;

    // -----------------------------------------------------------------
    // Table prefix
    // -----------------------------------------------------------------

    private static string $_tablePrefix = '';

    /**
     * Set a global prefix prepended to every Record subclass table name.
     * Clears the schema cache so subsequent schema builds pick up the new prefix.
     *
     * @api
     */
    public static function setTablePrefix(string $prefix): void
    {
        self::$_tablePrefix = $prefix;
        TableSchema::clearCache();
    }

    /** @api */
    public static function tablePrefix(): string
    {
        return self::$_tablePrefix;
    }

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
    // Factory / bulk-setter
    // -----------------------------------------------------------------

    /**
     * Create a new (unsaved) instance with the given attributes pre-populated.
     *
     * Equivalent to `new static()` followed by `set($attrs)`.
     *
     * @api
     *
     * @param array<string, mixed> $attrs
     *
     * @psalm-suppress UnsafeInstantiation
     */
    public static function newWith(array $attrs): static
    {
        return (new static())->set($attrs);
    }

    /**
     * Bulk-assign column properties and return $this for chaining.
     *
     * Only sets properties that exist as public column members on the record;
     * unknown keys are silently ignored.
     *
     * Calls {@see validate()} at the end by default to surface invariant violations
     * at the point of assignment. Pass `false` to defer validation (useful for
     * test fixtures or for staged construction that completes invariants over
     * multiple `set()` calls — but `save()` will still validate at the boundary).
     *
     * @api
     *
     * @param array<string, mixed> $attrs
     *
     * @throws RecordValidationException when `$validate` is true and `validate()` rejects the resulting state
     */
    public function set(array $attrs, bool $validate = true): static
    {
        /** @psalm-var mixed $value */
        foreach ($attrs as $key => $value) {
            $this->$key = $value;
        }

        if ($validate) {
            $this->validate();
        }

        return $this;
    }

    /**
     * Verify that the record's current property values satisfy its domain invariants.
     *
     * Subclasses override this hook to enforce field-level and cross-field constraints
     * (positive ids, non-empty required strings, mutually exclusive flags, etc.). The
     * base implementation is a no-op so records without invariants need not override.
     *
     * Called automatically by {@see set()} (when `$validate` is true) and by
     * {@see save()} and {@see RecordSet::upsertAll()} after `beforeSave()`. Throw
     * {@see RecordValidationException} (or a subclass) on violation.
     *
     * @api
     *
     * @throws RecordValidationException when an invariant is violated
     */
    public function validate(): void
    {
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
        $conn = static::connection();
        $sql = self::buildSelectSql($schema->tableName, $schema->pk, $forUpdate);
        // Route the id through the column serializer so a binary PK is wrapped for binding on a
        // dialect that needs it (PostgreSQL); int/string PKs and MySQL pass through unchanged.
        $idParam = ColumnSerializer::toParam($id, $schema->columns[$schema->pk], $conn->dialect->bindsBinaryAsLob());
        $row = $conn->session->fetchOne($sql, [$idParam]);

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
        $record->{static::schema()->pkProp} = $id;

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
            // Deadlock prevention: always lock in ascending PK order. The row-locking clause is
            // dialect-provided ('FOR UPDATE' on MySQL/PG; '' where the engine has no such clause).
            $qpk = static::qi($schema->pk);
            $forUpdateClause = static::connection()->dialect->forUpdateClause();
            $sql = trim("SELECT * FROM {$qt} {$whereClause} ORDER BY {$qpk} ASC {$forUpdateClause}");
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
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public static function findOne(
        string | WhereClause $where,
        array $params = [],
        string $orderByLimit = 'LIMIT 1',
        bool $forUpdate = false,
    ): ?static {
        // if $orderByLimit doesn't have a LIMIT set, add one to prevent fetching more rows than necessary
        if ($orderByLimit && !preg_match('/\blimit\b/i', $orderByLimit)) {
            $orderByLimit .= ' LIMIT 1';
        }

        return static::find($where, $params, $orderByLimit, $forUpdate)->first();
    }

    /**
     * Return the first record matching all `$match` columns, or a **new, unsaved** instance
     * populated with `$match + $defaults` if none exists.
     *
     * @api
     *
     * @param array<string, scalar|null> $match    columns → values, matched by equality (all AND-ed)
     * @param array<string, mixed>       $defaults extra columns set only on a newly-built record
     */
    public static function firstOrNew(array $match, array $defaults = []): static
    {
        return static::findOne(WhereClause::match($match))
            ?? static::newWith([...$match, ...$defaults]);
    }

    /**
     * Like {@see firstOrNew()}, but a newly-built record is **saved** before it is returned.
     *
     * @api
     *
     * @param array<string, scalar|null> $match
     * @param array<string, mixed>       $defaults
     */
    public static function findOrCreate(array $match, array $defaults = []): static
    {
        $found = static::findOne(WhereClause::match($match));
        if (null !== $found) {
            return $found;
        }

        return static::newWith([...$match, ...$defaults])->save();
    }

    /**
     * Find the first record matching `$match` and update it with `$values` (saved); or create and
     * save `$match + $values` if none exists. Returns the saved record either way.
     *
     * @api
     *
     * @param array<string, scalar|null> $match
     * @param array<string, mixed>       $values columns to set (on the found or the created record)
     */
    public static function updateOrCreate(array $match, array $values): static
    {
        $found = static::findOne(WhereClause::match($match));
        if (null !== $found) {
            return $found->set($values)->save();
        }

        return static::newWith([...$match, ...$values])->save();
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
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     */
    public static function countWhere(string | WhereClause $where, array $params = []): int
    {
        if ($where instanceof WhereClause) {
            $params = $where->params();
            $where = $where->render(static::connection()->dialect);
        }
        ['sql' => $normSql, 'params' => $normParams] = NamedPlaceholderSql::positional($where, $params);
        $schema = static::schema();
        $sql = 'SELECT COUNT(*) FROM '.static::qi($schema->tableName)." WHERE {$normSql}";

        return (int) static::connection()->session->fetchScalar($sql, $normParams);
    }

    /**
     * `SUM($column)` over matching rows — `0` when none match. Empty `$where` aggregates the whole table.
     *
     * @api
     *
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     */
    public static function sumWhere(string $column, string | WhereClause $where = '', array $params = []): int | float
    {
        $v = static::aggregateWhere('SUM', $column, $where, $params);
        if (null === $v) {
            return 0;
        }
        if (\is_int($v) || \is_float($v)) {
            return $v;
        }

        // Drivers return SUM as a numeric string — keep it an int unless it carries a fraction.
        return str_contains($v, '.') ? (float) $v : (int) $v;
    }

    /**
     * `AVG($column)` over matching rows, or null when none match.
     *
     * @api
     *
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     */
    public static function avgWhere(string $column, string | WhereClause $where = '', array $params = []): ?float
    {
        $v = static::aggregateWhere('AVG', $column, $where, $params);

        return null === $v ? null : (float) $v;
    }

    /**
     * `MIN($column)` over matching rows (raw value; null when none match).
     *
     * @api
     *
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     */
    public static function minWhere(string $column, string | WhereClause $where = '', array $params = []): string | int | float | null
    {
        return static::aggregateWhere('MIN', $column, $where, $params);
    }

    /**
     * `MAX($column)` over matching rows (raw value; null when none match).
     *
     * @api
     *
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     */
    public static function maxWhere(string $column, string | WhereClause $where = '', array $params = []): string | int | float | null
    {
        return static::aggregateWhere('MAX', $column, $where, $params);
    }

    /**
     * Whether any row matches `$where` (a single-row `SELECT 1 … LIMIT 1`).
     *
     * @api
     *
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     */
    public static function existsWhere(string | WhereClause $where = '', array $params = []): bool
    {
        $schema = static::schema();
        if ($where instanceof WhereClause) {
            $params = $where->params();
            $where = $where->render(static::connection()->dialect);
        }
        ['sql' => $normSql, 'params' => $normParams] = NamedPlaceholderSql::positional($where, $params);
        $sql = 'SELECT 1 FROM '.static::qi($schema->tableName);
        if ('' !== $normSql) {
            $sql .= ' WHERE '.$normSql;
        }

        return null !== static::connection()->session->fetchScalar($sql.' LIMIT 1', $normParams);
    }

    /**
     * Run a single-column SQL aggregate (`SUM`/`AVG`/`MIN`/`MAX`) over matching rows and return the
     * raw scalar. Empty `$where` aggregates the whole table.
     *
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     */
    private static function aggregateWhere(string $func, string $column, string | WhereClause $where, array $params): string | int | float | null
    {
        $schema = static::schema();
        if (!isset($schema->columns[$column])) {
            throw new SchemaException(sprintf('%sWhere: unknown column "%s" on %s.', strtolower($func), $column, static::class));
        }
        if ($where instanceof WhereClause) {
            $params = $where->params();
            $where = $where->render(static::connection()->dialect);
        }
        ['sql' => $normSql, 'params' => $normParams] = NamedPlaceholderSql::positional($where, $params);
        $sql = 'SELECT '.$func.'('.static::qi($column).') FROM '.static::qi($schema->tableName);
        if ('' !== $normSql) {
            $sql .= ' WHERE '.$normSql;
        }

        return static::connection()->session->fetchScalar($sql, $normParams);
    }

    /**
     * Throw if this Record class is {@see AppendOnly} — its rows are write-once, so the named
     * update/delete operation is forbidden. Insert paths (insertAll / new-record save) do not call this.
     */
    private static function assertNotAppendOnly(string $operation): void
    {
        if (is_a(static::class, AppendOnly::class, true)) {
            throw AppendOnlyViolationException::forOperation(static::class, $operation);
        }
    }

    /**
     * Execute a bulk UPDATE on all rows matching a WHERE clause.
     *
     * @api
     *
     * @param array<string, mixed>          $set    Column name → value pairs to write
     * @param string|WhereClause            $where  WHERE clause (? or :named placeholders), or a WhereClause instance;
     *                                              empty = update all rows
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     *
     * @return int Affected row count
     *
     * @throws SchemaException     when an unknown column name appears in $set
     * @throws RecordSaveException on DB error
     */
    public static function updateWhere(array $set, string | WhereClause $where = '', array $params = []): int
    {
        self::assertNotAppendOnly('updateWhere()');
        $schema = static::schema();
        $conn = static::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;

        if ($where instanceof WhereClause) {
            $params = $where->params();
            $where = $where->render($dialect);
        }
        ['sql' => $normWhere, 'params' => $normParams] = NamedPlaceholderSql::positional($where, $params);

        $setParts = [];
        $setParams = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($set as $name => $value) {
            if (!isset($schema->columns[$name])) {
                throw new SchemaException(sprintf('updateWhere: unknown column "%s" on %s.', $name, static::class));
            }
            if ($value instanceof RawSql) {
                $setParts[] = $dialect->quoteIdentifier($name).' = '.$value->expression;
                foreach ($value->params as $p) {
                    $setParams[] = $p;
                }
                continue;
            }
            $setParts[] = $dialect->quoteIdentifier($name).' = ?';
            $setParams[] = ColumnSerializer::toParam($value, $schema->columns[$name], $dialect->bindsBinaryAsLob());
        }

        if (empty($setParts)) {
            return 0;
        }

        if (null !== ($ts = self::autoUpdatedAtAssignment($schema, $dialect, $set))) {
            $setParts[] = $ts[0];
            $setParams[] = $ts[1];
        }

        if (null !== ($ver = self::autoVersionAssignment($schema, $dialect, $set))) {
            $setParts[] = $ver;
        }

        $qt = $dialect->quoteIdentifier($schema->tableName);
        $sql = 'UPDATE '.$qt.' SET '.implode(', ', $setParts);
        if ('' !== $normWhere) {
            $sql .= ' WHERE '.$normWhere;
        }

        try {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            return $session->exec($sql, array_merge($setParams, $normParams));
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }
    }

    /**
     * @api
     *
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     *
     * @return int Number of deleted rows
     */
    public static function deleteWhere(string | WhereClause $where, array $params = []): int
    {
        self::assertNotAppendOnly('deleteWhere()');
        if ($where instanceof WhereClause) {
            $params = $where->params();
            $where = $where->render(static::connection()->dialect);
        }
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
     * @throws RecordSaveException on DB error
     */
    /**
     * Called just before every save (insert or update). Override to set timestamps, defaults, etc.
     *
     * @internal called by Record::save() and RecordSet::upsertAll(); not part of the public API
     */
    public function beforeSave(): void
    {
    }

    /**
     * Called after a successful write (INSERT or UPDATE). Not called for a clean save that wrote
     * nothing. Fired by both {@see save()} and {@see RecordSet::upsertAll()} (per record). Override to
     * dispatch events, invalidate caches, etc.
     *
     * @param bool $wasInsert true if the write was an INSERT (new row), false if an UPDATE
     *
     * @psalm-suppress PossiblyUnusedParam base hook is empty; the param is for overriders
     */
    public function afterSave(bool $wasInsert): void
    {
    }

    /**
     * Set auto-managed timestamps before a write: {@see Attribute\CreatedAt}
     * on INSERT, {@see Attribute\UpdatedAt} on INSERT and on any UPDATE that
     * actually changes another column (a clean update does not bump it). No-op when neither
     * attribute is declared.
     *
     * @internal called by save() and RecordSet::upsertAll()
     *
     * @psalm-suppress PossiblyUnusedMethod called by RecordSet::upsertAll()
     */
    public function applyAutoTimestamps(bool $isInsert): void
    {
        $schema = static::schema();

        if ($isInsert) {
            $now = new \DateTimeImmutable();
            if (null !== $schema->createdAtColumn) {
                $this->{$schema->propFor($schema->createdAtColumn)} = $now;
            }
            if (null !== $schema->updatedAtColumn) {
                $this->{$schema->propFor($schema->updatedAtColumn)} = $now;
            }

            return;
        }

        if (null === $schema->updatedAtColumn) {
            return;
        }

        // Bump updated_at only when a real change is pending (ignore updated_at's own dirtiness).
        $dirty = $this->dirtyFields();
        unset($dirty[$schema->updatedAtColumn]);
        if (!empty($dirty)) {
            $this->{$schema->propFor($schema->updatedAtColumn)} = new \DateTimeImmutable();
        }
    }

    /**
     * Build the auto-managed `updated_at = now` assignment for the bulk-UPDATE paths
     * ({@see updateWhere()} / {@see updateByWhere()} / {@see updateByUniqueKey()}), so #[UpdatedAt]
     * stays consistent with {@see save()}. Returns `[setFragment, boundValue]`, or null when the
     * schema has no #[UpdatedAt] column or the caller already set that column ($setCols).
     *
     * @param array<string, mixed> $setCols columns already present in the SET (by key)
     *
     * @return array{0: string, 1: scalar|BinaryParam|null}|null
     */
    private static function autoUpdatedAtAssignment(TableSchema $schema, SqlDialect $dialect, array $setCols): ?array
    {
        $col = $schema->updatedAtColumn;
        if (null === $col || \array_key_exists($col, $setCols)) {
            return null;
        }

        return [
            $dialect->quoteIdentifier($col).' = ?',
            ColumnSerializer::toParam(new \DateTimeImmutable(), $schema->columns[$col], $dialect->bindsBinaryAsLob()),
        ];
    }

    /**
     * SET fragment incrementing the optimistic-locking version on a **set-based** UPDATE, or null
     * when the record has no `#[Version]` column or the caller set it explicitly.
     *
     * A set-based update cannot *guard* on a version — it matches rows by predicate, not from loaded
     * state, so there is no per-row expected value to compare. It must still **bump** it: leaving the
     * version untouched would let a stale holder's guarded write match afterwards and silently
     * clobber this update, which is exactly what the version exists to prevent.
     *
     * Returns a bare expression (no bound parameter), so callers append it to the SET list only.
     *
     * @param array<string, mixed> $setCols columns the caller is already setting
     */
    private static function autoVersionAssignment(TableSchema $schema, SqlDialect $dialect, array $setCols): ?string
    {
        $col = $schema->versionColumn;
        if (null === $col || \array_key_exists($col, $setCols)) {
            return null;
        }
        $quoted = $dialect->quoteIdentifier($col);

        return "{$quoted} = {$quoted} + 1";
    }

    /**
     * Seed the optimistic-locking version on a record about to be INSERTed, so the stored value is
     * deterministic and matches memory without relying on a DDL default. No-op when the record has no
     * `#[Version]` column, or when the caller already set one.
     *
     * @internal called by save() and by the bulk insert paths
     */
    public function seedVersionForInsert(): void
    {
        $schema = static::schema();
        if (null !== ($col = $schema->versionColumn)) {
            $this->{$schema->columns[$col]->propertyName} ??= 1;
        }
    }

    /**
     * @param list<string>|null      $ignoreColumns Column names to drop from the write. Purely
     *                                              subtractive: on INSERT an omitted column lets its DB
     *                                              default fire (nullable or not); on UPDATE it is left
     *                                              out of the SET (untouched). `null`/`[]` = ignore
     *                                              nothing. An unknown column name throws SchemaException.
     * @param bool|list<string>|null $readBack      After the write, re-read column(s) from the DB and
     *                                              hydrate this record so values it omitted (fired DB
     *                                              defaults, generated columns) reflect their stored form
     *                                              and the record reads back clean. `true` re-reads the
     *                                              whole row (fires afterLoad()); `false` never; a
     *                                              `list<string>` re-reads exactly those columns (targeted
     *                                              patch, no afterLoad; unknown name throws
     *                                              SchemaException); `null` = auto — read back the ignored
     *                                              nullable-with-default column(s) plus any generated
     *                                              column, and nothing on a plain save (so it costs
     *                                              nothing on the default path).
     */
    public function save(bool $force = false, ?array $ignoreColumns = null, bool | array | null $readBack = null): static
    {
        // Append-only rows are write-once: a new-record save (INSERT) is a legitimate append,
        // but saving an existing record (UPDATE) is forbidden.
        if (!$this->_isNew && $this instanceof AppendOnly) {
            throw AppendOnlyViolationException::forOperation(static::class, 'save() on an existing row (UPDATE)');
        }

        $this->beforeSave();
        $this->applyAutoTimestamps($this->_isNew);
        $this->validate();

        $schema = static::schema();
        $conn = static::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;
        $wasInsert = $this->_isNew;

        // Validate the (column-name) ignore list up front so a typo fails loudly rather than
        // silently writing a column the caller meant to drop.
        $ignore = [];
        foreach ($ignoreColumns ?? [] as $ignoredName) {
            if (!isset($schema->columns[$ignoredName])) {
                throw new SchemaException(
                    sprintf('save(ignoreColumns:): unknown column "%s" on %s.', $ignoredName, static::class),
                );
            }
            $ignore[$ignoredName] = true;
        }

        // Resolve (and validate) the read-back mode up front, so a bad explicit list throws before
        // the write rather than after it; the 'auto' set is computed post-write from $writtenCols.
        $readBackMode = self::resolveReadBackMode($readBack, $schema, static::class);

        // Optimistic locking: seed a new record's version so the stored value is deterministic and
        // matches memory, and capture the loaded value to guard the UPDATE with.
        $versionCol = $schema->versionColumn;
        $expectedVersion = null;
        if (null !== $versionCol) {
            if ($this->_isNew) {
                $this->seedVersionForInsert();
            } else {
                /** @psalm-suppress MixedAssignment */
                $expectedVersion = (int) ($this->{$schema->columns[$versionCol]->propertyName} ?? 0);
            }
        }

        $colNames = [];
        $setParts = [];
        $params = [];
        /** @var array<string, true> $writtenCols columns this write actually emits (for auto read-back) */
        $writtenCols = [];
        $bindBinaryAsLob = $dialect->bindsBinaryAsLob();

        foreach ($schema->columns as $colName => $col) {
            // Skip columns that are not application-writable: auto-increment PKs are
            // assigned by the DB on INSERT, and database-generated columns are computed
            // by the DB on every write (their PHP property is read-only).
            if ($col->autoIncrement || $col->isGenerated) {
                continue;
            }

            // Caller-requested drop: subtract this column from the write. On INSERT its DB default
            // fires; on UPDATE it stays out of the SET (untouched).
            if (isset($ignore[$colName])) {
                continue;
            }

            // The optimistic-locking version is attrecord's to manage: on UPDATE it is emitted as a
            // `= <col> + 1` expression alongside a `<col> = <loaded value>` guard (below), never as a
            // caller-supplied literal. On INSERT it is written normally, seeded just above.
            if (!$this->_isNew && $colName === $schema->versionColumn) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $value = $this->{$col->propertyName} ?? null;

            // On INSERT, omit a NOT NULL column left null when it declares a DB default, so the
            // default fires (e.g. `recorded_at DEFAULT CURRENT_TIMESTAMP(6)`). Emitting an explicit
            // NULL here would only violate the NOT NULL constraint, so the caller can't have meant
            // it. Nullable-with-default columns are deliberately left alone: there, a null value
            // may genuinely mean "store NULL" rather than "use the default".
            if (
                $this->_isNew
                && null === $value
                && !$col->nullable
                && (null !== $col->default || null !== $col->defaultExpr)
            ) {
                continue;
            }

            if (!$force && !$this->_isNew) {
                $snapshot = ColumnSerializer::toSnapshotString($value, $col);
                if ($snapshot === ($this->_snapshot[$colName] ?? null)) {
                    continue; // clean
                }
            }

            $qcol = $dialect->quoteIdentifier($colName);
            $colNames[] = $qcol;
            $setParts[] = "{$qcol} = ?";
            $params[] = ColumnSerializer::toParam($value, $col, $bindBinaryAsLob);
            $writtenCols[$colName] = true;
        }

        if (empty($colNames)) {
            $this->_saved = false;

            return $this;
        }

        $qt = $dialect->quoteIdentifier($schema->tableName);
        $pk = $schema->pk;
        $pkProp = $schema->pkProp;
        $qpk = $dialect->quoteIdentifier($pk);

        // Resolve the read-back columns now (auto uses the just-built $writtenCols), so a supporting
        // dialect can fold them into the write's RETURNING clause rather than a second round-trip.
        $readBackCols = 'auto' === $readBackMode
            ? self::autoReadBackColumns($schema, $writtenCols, $wasInsert)
            : $readBackMode;
        $wantReadBack = null !== $readBackCols && [] !== $readBackCols;
        $canReturn = $dialect->supportsReturning();
        // Unquoted columns to fold into RETURNING: a targeted list, or every column for 'all'.
        $returnCols = 'all' === $readBackCols
            ? array_keys($schema->columns)
            : (is_array($readBackCols) ? $readBackCols : []);

        $returnedRow = null;
        try {
            if ($this->_isNew) {
                $cols = implode(', ', $colNames);
                $placeholders = implode(', ', array_fill(0, count($colNames), '?'));
                $insertSql = "INSERT INTO {$qt} ({$cols}) VALUES ({$placeholders})";
                if ($canReturn) {
                    // PG/SQLite: one round-trip returns the generated PK plus any folded read-back
                    // columns (a generated column returns its computed value).
                    $names = array_values(array_unique([$pk, ...$returnCols]));
                    $returning = 'RETURNING '.implode(', ', array_map($dialect->quoteIdentifier(...), $names));
                    /** @psalm-suppress MixedArgumentTypeCoercion */
                    $returnedRow = $session->fetchOne("{$insertSql} {$returning}", $params);
                    /** @psalm-suppress MixedAssignment, MixedArgument */
                    $rawPk = $returnedRow[$pk]
                        ?? throw new RecordSaveException('INSERT did not return a generated key.');
                    // Cast the returned key through the serializer: PostgreSQL returns a bigint
                    // PK as a string and a bytea PK as a stream resource — fromDb() normalises
                    // both to the property's PHP type (int / raw bytes).
                    $this->{$pkProp} = ColumnSerializer::fromDb($rawPk, $schema->columns[$pk], $returnedRow ?? []);
                } else {
                    /** @psalm-suppress MixedArgumentTypeCoercion */
                    $session->exec($insertSql, $params);
                    // Only backfill the PK from lastInsertId() when the PK is
                    // an auto-increment integer. For application-minted PKs
                    // (e.g. BINARY(16) UUIDs), the application already set
                    // $this->{$pkProp} before save() and lastInsertId() is meaningless.
                    if ($schema->columns[$pk]->autoIncrement) {
                        $this->{$pkProp} = $session->lastInsertId();
                    }
                }
                $this->_isNew = false;
            } else {
                $versionGuard = '';
                /** @psalm-suppress MixedAssignment */
                $rawPkForLock = $this->{$pkProp} ?? '';
                $lockId = \is_int($rawPkForLock) || \is_string($rawPkForLock) ? $rawPkForLock : '';
                if (null !== $versionCol) {
                    // Bump as an expression (never a caller literal) and guard on the loaded value.
                    // The bump also guarantees a matched row genuinely changes, so MySQL's
                    // changed-rows reporting cannot masquerade as a version conflict.
                    $qver = $dialect->quoteIdentifier($versionCol);
                    $setParts[] = "{$qver} = {$qver} + 1";
                    $versionGuard = " AND {$qver} = ?";
                }
                // Route the PK through the serializer so a binary PK is wrapped for binding.
                $params[] = ColumnSerializer::toParam($this->{$pkProp} ?? null, $schema->columns[$pk], $bindBinaryAsLob);
                if (null !== $versionCol) {
                    $params[] = $expectedVersion;
                }
                $updateSql = "UPDATE {$qt} SET ".implode(', ', $setParts)." WHERE {$qpk} = ?{$versionGuard}";
                if ($canReturn && $wantReadBack) {
                    // Fold the read-back into UPDATE … RETURNING (PG/SQLite) — no separate SELECT.
                    $returning = 'RETURNING '.implode(', ', array_map($dialect->quoteIdentifier(...), $returnCols));
                    /** @psalm-suppress MixedArgumentTypeCoercion */
                    $returnedRow = $session->fetchOne("{$updateSql} {$returning}", $params);
                    // No row came back: with a version guard that means the guard failed.
                    if (null !== $versionCol && null === $returnedRow) {
                        throw new OptimisticLockException(static::class, $lockId, (int) $expectedVersion);
                    }
                } else {
                    /** @psalm-suppress MixedArgumentTypeCoercion */
                    $affected = $session->exec($updateSql, $params);
                    if (null !== $versionCol && 0 === $affected) {
                        throw new OptimisticLockException(static::class, $lockId, (int) $expectedVersion);
                    }
                }
                if (null !== $versionCol) {
                    // The write succeeded, so the stored version is now one past what we guarded on.
                    $this->{$schema->columns[$versionCol]->propertyName} = (int) $expectedVersion + 1;
                }
            }
        } catch (RecordSaveException | OptimisticLockException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        $this->refreshSnapshot($schema);
        $this->_saved = true;
        $this->afterSave($wasInsert);

        if ($wantReadBack) {
            // Prefer the row RETURNING already handed us; otherwise (MySQL/MariaDB) a scoped SELECT.
            $row = $returnedRow;
            if (null === $row && !$canReturn) {
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $row = $session->fetchOne(
                    self::buildSelectSql($schema->tableName, $pk, false),
                    [ColumnSerializer::toParam($this->{$pkProp} ?? null, $schema->columns[$pk], $bindBinaryAsLob)],
                );
            }
            if (null !== $row) {
                if ('all' === $readBackCols) {
                    $this->hydrateFromRow($row);
                } else {
                    /** @var list<string> $readBackCols */
                    $this->patchColumnsFromRow($row, $readBackCols);
                }
            }
        }

        return $this;
    }

    /**
     * Resolve the read-back *mode* for {@see save()} and the bulk writers, validating an explicit
     * column list up front (before the write). Explicit forms win; `null` defers to auto:
     *
     *  - `false`        → `null`   (skip — no read-back)
     *  - `true`         → `'all'`  (re-read the whole row via {@see hydrateFromRow()}; fires afterLoad())
     *  - `list<string>` → those columns (validated; unknown name throws SchemaException); `[]` → `null`
     *  - `null`         → `'auto'` — resolved post-write by {@see autoReadBackColumns()} from the
     *                     columns actually written
     *
     * @internal shared with {@see RecordSet::insertAll()} / {@see RecordSet::upsertAll()}
     *
     * @param bool|list<string>|null $readBack
     *
     * @return 'all'|'auto'|list<string>|null
     *
     * @throws SchemaException when an explicit column list names a column not on the record
     */
    public static function resolveReadBackMode(bool | array | null $readBack, TableSchema $schema, string $recordClass): string | array | null
    {
        if (false === $readBack) {
            return null;
        }
        if (true === $readBack) {
            return 'all';
        }
        if (is_array($readBack)) {
            foreach ($readBack as $name) {
                if (!isset($schema->columns[$name])) {
                    throw new SchemaException(sprintf('readBack: unknown column "%s" on %s.', $name, $recordClass));
                }
            }

            return [] === $readBack ? null : $readBack;
        }

        return 'auto';
    }

    /**
     * The columns auto read-back should refresh, given the columns a write actually wrote: every
     * column attrecord's own write decisions left diverged from the in-memory value. On INSERT that
     * is each omitted column the DB populates from a `default`/`defaultExpr` (an ignored column, or a
     * NOT-NULL null-with-default omitted by the insert rule); on any write it is the generated columns
     * a written column feeds into (see {@see TableSchema::generatedColumnsAffectedBy()}). Empty when
     * nothing diverged, so auto is a no-op on a write that populated no DB-side value.
     *
     * @internal shared with the bulk writers
     *
     * @param array<string, true> $writtenCols columns this write actually wrote
     *
     * @return list<string>
     */
    public static function autoReadBackColumns(TableSchema $schema, array $writtenCols, bool $wasInsert): array
    {
        $cols = [];
        if ($wasInsert) {
            foreach ($schema->columns as $name => $col) {
                if (isset($writtenCols[$name]) || $col->autoIncrement || $col->isGenerated) {
                    continue;
                }
                if (null !== $col->default || null !== $col->defaultExpr) {
                    $cols[$name] = true;
                }
            }
        }
        foreach ($schema->generatedColumnsAffectedBy($writtenCols) as $generatedName) {
            $cols[$generatedName] = true;
        }

        return array_keys($cols);
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
        self::assertNotAppendOnly('delete()');
        $this->beforeDelete();

        $schema = static::schema();
        $pk = $schema->pk;
        /** @psalm-suppress MixedAssignment */
        $pkVal = $this->{$schema->pkProp};

        if (null === $pkVal) {
            throw new RecordDeleteException('Cannot delete a record with no primary key value.');
        }

        try {
            $conn = static::connection();
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $conn->session->exec(
                'DELETE FROM '.$conn->dialect->quoteIdentifier($schema->tableName)
                    .' WHERE '.$conn->dialect->quoteIdentifier($pk).' = ?',
                [ColumnSerializer::toParam($pkVal, $schema->columns[$pk], $conn->dialect->bindsBinaryAsLob())],
            );
        } catch (\Throwable $e) {
            throw new RecordDeleteException($e->getMessage(), $e);
        }

        $this->_snapshot = [];
        $this->_isNew = true;
        $this->afterDelete();
    }

    /**
     * Called before {@see delete()} removes the row. Override to cascade, guard, or dispatch.
     * Bulk {@see RecordSet::deleteAll()} does NOT fire this (set-based delete, no per-row hooks).
     */
    public function beforeDelete(): void
    {
    }

    /**
     * Called after {@see delete()} successfully removes the row (the record is now marked new).
     * Bulk {@see RecordSet::deleteAll()} does NOT fire this.
     */
    public function afterDelete(): void
    {
    }

    /**
     * Called after this record's columns have been hydrated from a DB row (single fetch or bulk
     * load). Override to derive transient state from loaded columns.
     */
    public function afterLoad(): void
    {
    }

    /**
     * INSERT this record; on unique key conflict, UPDATE the given columns instead.
     *
     * All non-autoIncrement columns are included in the INSERT. On conflict on the
     * named unique key, only $updateColumns are overwritten. After execution the
     * snapshot is refreshed and the record is marked as not-new.
     *
     * @api
     *
     * @param string       $conflictKey   Name of a #[UniqueKey] declared on this Record class
     * @param list<string> $updateColumns Non-PK columns to overwrite on conflict
     *
     * @throws AttrecordException  when $conflictKey is not declared on this Record
     * @throws RecordSaveException on DB error
     */
    public function upsertByUniqueKey(string $conflictKey, array $updateColumns, bool $preserveAutoIncrement = false): void
    {
        $schema = static::schema();
        $conn = static::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;

        $conflictCols = $schema->uniqueKeys[$conflictKey]
            ?? throw new AttrecordException(
                sprintf('upsertByUniqueKey: unknown unique key "%s" on %s.', $conflictKey, static::class),
            );

        if ($preserveAutoIncrement) {
            $this->upsertByUniqueKeyPreservingAutoIncrement($schema, $conflictCols, $updateColumns);

            return;
        }

        $columnNames = [];
        $params = [];
        foreach ($schema->columns as $colName => $col) {
            if ($col->autoIncrement || $col->isGenerated) {
                continue;
            }
            $columnNames[] = $colName;
            /** @psalm-suppress MixedAssignment */
            $params[] = ColumnSerializer::toParam($this->{$col->propertyName} ?? null, $col, $dialect->bindsBinaryAsLob());
        }

        $sql = $dialect->buildSingleUpsertSql($schema->tableName, $columnNames, $conflictCols, $updateColumns);

        try {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $session->exec($sql, $params);
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        $this->_isNew = false;
        $this->refreshSnapshot($schema);
    }

    /**
     * Burn-free variant of {@see upsertByUniqueKey()}: SELECT the row by the conflict key,
     * then UPDATE it in place (no auto-increment allocation) or INSERT a brand-new one.
     *
     * `INSERT … ON DUPLICATE KEY UPDATE` allocates (and discards) an auto-increment value on
     * every conflicting write, inflating the counter on idempotent re-writes. This variant
     * never INSERTs into an existing row, so the AI counter only advances for genuinely-new
     * rows. The cost is a second statement (a small SELECT-then-write race window) — fine for
     * low-concurrency registry/config writes; prefer the atomic default when burn is a non-issue.
     *
     * @param list<string> $conflictCols
     * @param list<string> $updateColumns
     */
    private function upsertByUniqueKeyPreservingAutoIncrement(
        TableSchema $schema,
        array $conflictCols,
        array $updateColumns,
    ): void {
        $conn = static::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;
        $pk = $schema->pk;
        $qt = $dialect->quoteIdentifier($schema->tableName);
        $qpk = $dialect->quoteIdentifier($pk);

        $whereParts = [];
        $whereParams = [];
        foreach ($conflictCols as $colName) {
            $col = $schema->columns[$colName];
            $whereParts[] = $dialect->quoteIdentifier($colName).' = ?';
            $whereParams[] = ColumnSerializer::toParam($this->{$col->propertyName} ?? null, $col, $dialect->bindsBinaryAsLob());
        }

        try {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $existing = $session->fetchOne(
                sprintf('SELECT %s FROM %s WHERE %s LIMIT 1', $qpk, $qt, implode(' AND ', $whereParts)),
                $whereParams,
            );

            if (null !== $existing) {
                $setParts = [];
                $setParams = [];
                foreach ($updateColumns as $colName) {
                    $col = $schema->columns[$colName]
                        ?? throw new SchemaException(sprintf('upsertByUniqueKey: unknown column "%s" on %s.', $colName, static::class));
                    $setParts[] = $dialect->quoteIdentifier($colName).' = ?';
                    $setParams[] = ColumnSerializer::toParam($this->{$col->propertyName} ?? null, $col, $dialect->bindsBinaryAsLob());
                }
                /** @psalm-suppress MixedAssignment */
                $pkValue = $existing[$pk];
                if (!empty($setParts)) {
                    $setParams[] = $pkValue;
                    /** @psalm-suppress MixedArgumentTypeCoercion */
                    $session->exec(sprintf('UPDATE %s SET %s WHERE %s = ?', $qt, implode(', ', $setParts), $qpk), $setParams);
                }
                $this->{$schema->pkProp} = ColumnSerializer::fromDb($pkValue, $schema->columns[$pk], $existing);
            } else {
                $columnNames = [];
                $params = [];
                foreach ($schema->columns as $colName => $col) {
                    if ($col->autoIncrement || $col->isGenerated) {
                        continue;
                    }
                    $columnNames[] = $colName;
                    /** @psalm-suppress MixedAssignment */
                    $params[] = ColumnSerializer::toParam($this->{$col->propertyName} ?? null, $col, $dialect->bindsBinaryAsLob());
                }
                $quotedCols = implode(', ', array_map($dialect->quoteIdentifier(...), $columnNames));
                $placeholders = implode(', ', array_fill(0, count($columnNames), '?'));
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $session->exec(sprintf('INSERT INTO %s (%s) VALUES (%s)', $qt, $quotedCols, $placeholders), $params);
                if ($schema->columns[$pk]->autoIncrement) {
                    $this->{$schema->pkProp} = $session->lastInsertId();
                }
            }
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }

        $this->_isNew = false;
        $this->refreshSnapshot($schema);
    }

    /**
     * Execute a direct UPDATE without loading the record from the DB first.
     *
     * WHERE clause is auto-selected: uses the PK value if non-null; otherwise
     * falls back to the first #[UniqueKey] whose columns are all non-null.
     *
     * @api
     *
     * @param list<string> $fields Columns to include in SET. If empty, all non-null
     *                             non-PK non-autoIncrement columns are updated.
     *                             Pass an explicit list to update columns to null.
     *
     * @return int Affected row count (0 if no matching row, 1 on success)
     *
     * @throws AttrecordException  when no viable WHERE clause can be built
     * @throws SchemaException     when an unknown column name is given in $fields
     * @throws RecordSaveException on DB error
     */
    public function updateByUniqueKey(array $fields = []): int
    {
        $schema = static::schema();
        $conn = static::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;

        // --- WHERE clause ---
        $pk = $schema->pk;
        /** @psalm-suppress MixedAssignment */
        $pkVal = $this->{$schema->pkProp} ?? null;
        $whereParts = [];
        $whereParams = [];

        if (null !== $pkVal) {
            $whereParts[] = $dialect->quoteIdentifier($pk).' = ?';
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $whereParams[] = ColumnSerializer::toParam($pkVal, $schema->columns[$pk], $dialect->bindsBinaryAsLob());
        } else {
            foreach ($schema->uniqueKeys as $keyColumns) {
                $allSet = true;
                foreach ($keyColumns as $colName) {
                    $colDef = $schema->columns[$colName];
                    if (null === ($this->{$colDef->propertyName} ?? null)) {
                        $allSet = false;
                        break;
                    }
                }
                if ($allSet) {
                    foreach ($keyColumns as $colName) {
                        $colDef = $schema->columns[$colName];
                        $whereParts[] = $dialect->quoteIdentifier($colName).' = ?';
                        /** @psalm-suppress MixedAssignment */
                        $whereParams[] = ColumnSerializer::toParam($this->{$colDef->propertyName} ?? null, $colDef, $dialect->bindsBinaryAsLob());
                    }
                    break;
                }
            }
        }

        if (empty($whereParts)) {
            throw new AttrecordException(
                sprintf(
                    'updateByUniqueKey on %s: no PK or unique key with all values non-null to use as WHERE clause.',
                    static::class,
                ),
            );
        }

        // --- SET clause ---
        $setParts = [];
        $setParams = [];
        $setCols = [];

        if (empty($fields)) {
            foreach ($schema->columns as $colName => $col) {
                // Skip updated_at in the implicit all-columns set so it is stamped `now`, not the
                // record's (possibly stale) property value.
                if ($col->autoIncrement || $col->isGenerated || $colName === $pk || $colName === $schema->updatedAtColumn) {
                    continue;
                }
                /** @psalm-suppress MixedAssignment */
                $value = $this->{$col->propertyName} ?? null;
                if (null !== $value) {
                    $setParts[] = $dialect->quoteIdentifier($colName).' = ?';
                    $setParams[] = ColumnSerializer::toParam($value, $col, $dialect->bindsBinaryAsLob());
                    $setCols[$colName] = true;
                }
            }
        } else {
            foreach ($fields as $colName) {
                $col = $schema->columns[$colName]
                    ?? throw new SchemaException(sprintf('updateByUniqueKey: unknown column "%s".', $colName));
                /** @psalm-suppress MixedAssignment */
                $value = $this->{$col->propertyName} ?? null;
                $setParts[] = $dialect->quoteIdentifier($colName).' = ?';
                $setParams[] = ColumnSerializer::toParam($value, $col, $dialect->bindsBinaryAsLob());
                $setCols[$colName] = true;
            }
        }

        if (empty($setParts)) {
            return 0;
        }

        if (null !== ($ts = self::autoUpdatedAtAssignment($schema, $dialect, $setCols))) {
            $setParts[] = $ts[0];
            $setParams[] = $ts[1];
        }

        if (null !== ($ver = self::autoVersionAssignment($schema, $dialect, $setCols))) {
            $setParts[] = $ver;
        }

        $qt = $dialect->quoteIdentifier($schema->tableName);
        $sql = 'UPDATE '.$qt.' SET '.implode(', ', $setParts).' WHERE '.implode(' AND ', $whereParts);
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $params = array_merge($setParams, $whereParams);

        try {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            return $session->exec($sql, $params);
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }
    }

    /**
     * Execute a bulk UPDATE on all rows matching the given WHERE clause.
     *
     * The SET clause is built from instance properties using the same semantics as
     * updateByUniqueKey(): when $fields is empty, all non-null non-PK non-autoIncrement
     * columns are updated; when $fields is provided, exactly those columns are written
     * (allowing null values).
     *
     * Useful when type-safe value assignment via record properties is preferred over the
     * plain array<string, mixed> of the static updateWhere(), but the WHERE clause is
     * not derivable from a PK or declared unique key.
     *
     * @api
     *
     * @param string|WhereClause            $where  WHERE clause (? or :named placeholders), or a WhereClause instance;
     *                                              empty = update all rows
     * @param array<array-key, scalar|null> $params ignored when $where is a WhereClause
     * @param list<string>                  $fields Columns to include in SET. If empty, all non-null
     *                                              non-PK non-autoIncrement columns are updated.
     *                                              Pass an explicit list to update columns to null.
     *
     * @return int Affected row count
     *
     * @throws SchemaException     when an unknown column name is given in $fields
     * @throws RecordSaveException on DB error
     */
    public function updateByWhere(string | WhereClause $where = '', array $params = [], array $fields = []): int
    {
        self::assertNotAppendOnly('updateByWhere()');
        $schema = static::schema();
        $conn = static::connection();
        $dialect = $conn->dialect;
        $session = $conn->session;

        if ($where instanceof WhereClause) {
            $params = $where->params();
            $where = $where->render($dialect);
        }

        ['sql' => $normWhere, 'params' => $normParams] = NamedPlaceholderSql::positional($where, $params);

        $pk = $schema->pk;
        $setParts = [];
        $setParams = [];

        $setCols = [];
        if (empty($fields)) {
            foreach ($schema->columns as $colName => $col) {
                // Skip updated_at in the implicit all-columns set so it is stamped `now`, not the
                // record's (possibly stale) property value.
                if ($col->autoIncrement || $col->isGenerated || $colName === $pk || $colName === $schema->updatedAtColumn) {
                    continue;
                }
                /** @psalm-suppress MixedAssignment */
                $value = $this->{$col->propertyName} ?? null;
                if (null !== $value) {
                    $setParts[] = $dialect->quoteIdentifier($colName).' = ?';
                    $setParams[] = ColumnSerializer::toParam($value, $col, $dialect->bindsBinaryAsLob());
                    $setCols[$colName] = true;
                }
            }
        } else {
            foreach ($fields as $colName) {
                $col = $schema->columns[$colName]
                    ?? throw new SchemaException(sprintf('updateByWhere: unknown column "%s".', $colName));
                /** @psalm-suppress MixedAssignment */
                $value = $this->{$col->propertyName} ?? null;
                $setParts[] = $dialect->quoteIdentifier($colName).' = ?';
                $setParams[] = ColumnSerializer::toParam($value, $col, $dialect->bindsBinaryAsLob());
                $setCols[$colName] = true;
            }
        }

        if (empty($setParts)) {
            return 0;
        }

        if (null !== ($ts = self::autoUpdatedAtAssignment($schema, $dialect, $setCols))) {
            $setParts[] = $ts[0];
            $setParams[] = $ts[1];
        }

        if (null !== ($ver = self::autoVersionAssignment($schema, $dialect, $setCols))) {
            $setParts[] = $ver;
        }

        $qt = $dialect->quoteIdentifier($schema->tableName);
        $sql = 'UPDATE '.$qt.' SET '.implode(', ', $setParts);
        if ('' !== $normWhere) {
            $sql .= ' WHERE '.$normWhere;
        }

        try {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            return $session->exec($sql, array_merge($setParams, $normParams));
        } catch (\Throwable $e) {
            throw new RecordSaveException($e->getMessage(), $e);
        }
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
        $pk = $schema->pk;
        /** @psalm-suppress MixedAssignment */
        $pkVal = $this->{$schema->pkProp};

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

        foreach ($check as $colName) {
            $col = $schema->columns[$colName];
            /** @psalm-suppress MixedAssignment */
            $current = ColumnSerializer::toSnapshotString($this->{$col->propertyName} ?? null, $col);
            $snapshot = $this->_snapshot[$colName] ?? null;

            if ($current !== $snapshot) {
                $dirty[$colName] = [$snapshot, $current];
            }
        }

        return $dirty;
    }

    /**
     * Imperatively load one or more relations (names or dot-notation chains) onto this record.
     *
     * The single-record counterpart to {@see RecordSet::load()} — same semantics: runs immediately,
     * one IN(…) query per distinct relation level, shared prefixes across the given paths load once.
     *
     *     $order->load('lines', 'customer.billing');
     *
     * @return static fluent
     */
    public function load(string ...$relationPaths): static
    {
        (new RecordSet([$this]))->load(...$relationPaths);

        return $this;
    }

    /**
     * Like {@see load()}, but skips relations already loaded on this record — the single-record
     * counterpart to {@see RecordSet::loadMissing()}.
     *
     * @return static fluent
     */
    public function loadMissing(string ...$relationPaths): static
    {
        (new RecordSet([$this]))->loadMissing(...$relationPaths);

        return $this;
    }

    /**
     * Mark a relation as loaded on this record. Called by {@see RecordSet::load()} after it
     * populates the relation property, so a later {@see RecordSet::loadMissing()} skips it.
     *
     * @internal
     */
    public function markRelationLoaded(string $relation): void
    {
        $this->_loadedRelations[$relation] = true;
    }

    /**
     * Whether a relation has been loaded onto this record (regardless of whether the loaded
     * value is null / an empty set). Manual assignment to the relation property is not tracked.
     *
     * @api
     */
    public function relationIsLoaded(string $relation): bool
    {
        return isset($this->_loadedRelations[$relation]);
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

        foreach ($schema->columns as $colName => $col) {
            $raw = $row[$colName] ?? null;
            /** @psalm-suppress MixedAssignment */
            $value = ColumnSerializer::fromDb($raw, $col, $row);
            $this->{$col->propertyName} = $value;
            // For casted columns, snapshot the re-encoded canonical form rather than the
            // raw DB bytes: native JSON storage normalizes (key order / whitespace), so
            // comparing our own encoding on both sides keeps a freshly loaded, untouched
            // record clean instead of falsely dirty.
            // Binary columns snapshot from the decoded value too: on PostgreSQL the raw
            // bytea arrives as a single-read stream that fromDb() has already consumed, so
            // (string) $raw would be empty/garbage here.
            $this->_snapshot[$colName] = (null !== $col->caster || $col->isBinary)
                ? ColumnSerializer::toSnapshotString($value, $col)
                : (null !== $raw ? (string) $raw : null);
        }

        $this->_isNew = false;
        $this->afterLoad();
    }

    /**
     * Patch a subset of columns onto this record from a raw DB row — updates each named column's
     * property AND its dirty-snapshot entry (so it reads back clean) while touching nothing else,
     * and does NOT fire afterLoad(). This is the targeted post-write read-back primitive; contrast
     * {@see hydrateFromRow()}, which reloads the whole row and fires afterLoad().
     *
     * @param array<string, scalar|null> $row
     * @param list<string>               $colNames
     *
     * @internal used by RecordSet's bulk read-back and save()'s read-back
     */
    public function patchColumnsFromRow(array $row, array $colNames): void
    {
        $schema = static::schema();

        foreach ($colNames as $colName) {
            $col = $schema->columns[$colName];
            $raw = $row[$colName] ?? null;
            /** @psalm-suppress MixedAssignment */
            $value = ColumnSerializer::fromDb($raw, $col, $row);
            $this->{$col->propertyName} = $value;
            // Snapshot the same way hydrateFromRow() does (canonical form for casted/binary columns).
            $this->_snapshot[$colName] = (null !== $col->caster || $col->isBinary)
                ? ColumnSerializer::toSnapshotString($value, $col)
                : (null !== $raw ? (string) $raw : null);
        }
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
     * Called after RecordSet::upsertAll() so dirty tracking reflects the written state.
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
        foreach ($schema->columns as $colName => $col) {
            // Default (raw-string) binary binding keeps this export scalar; unwrap defensively.
            /** @psalm-suppress MixedAssignment */
            $value = ColumnSerializer::toParam($this->{$col->propertyName} ?? null, $col);
            $out[$colName] = $value instanceof BinaryParam ? $value->bytes : $value;
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
        $forUpdatePart = $forUpdate ? $dialect->forUpdateClause() : '';

        return rtrim("SELECT * FROM {$qt} WHERE {$qpk} = ? {$orderPart}{$forUpdatePart}");
    }

    private function refreshSnapshot(TableSchema $schema): void
    {
        foreach ($schema->columns as $colName => $col) {
            $this->_snapshot[$colName] = ColumnSerializer::toSnapshotString($this->{$col->propertyName} ?? null, $col);
        }
    }
}
