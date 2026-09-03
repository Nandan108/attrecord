<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Attribute\Absent;
use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Attribute\Check;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Mutable as MutableAttr;
use Nandan108\Attrecord\Attribute\MysqlTableOptions;
use Nandan108\Attrecord\Attribute\PrimaryKey as PrimaryKeyAttr;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Attribute\Unmanaged;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Attribute\Version;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Caster\JsonCaster;
use Nandan108\Attrecord\Caster\SetCaster;
use Nandan108\Attrecord\ColumnCaster;
use Nandan108\Attrecord\Enum\ColumnRole;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Enum\SchemaObjectKind;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\JsonCastable;

/**
 * Compiled, cached schema for one Record subclass.
 *
 * Built once via reflection; all subsequent accesses are O(1) array lookups.
 *
 * @api
 */
final class TableSchema
{
    /**
     * Maximum identifier length shared by MySQL, MariaDB and PostgreSQL (PG's `NAMEDATALEN` is 63,
     * one less, but its FK names are per-table and it never sees the collision this guards).
     */
    private const MAX_IDENTIFIER_LENGTH = 64;

    /** @var array<string, ColumnDefinition>  column name → definition */
    public readonly array $columns;

    /** @var array<string, RelationDefinition>  property name → definition */
    public readonly array $relations;

    /** @var array<string, \ReflectionProperty>  column name → cached \ReflectionProperty */
    public readonly array $reflProperties;

    /** @var list<string> column names excluding the PK */
    public readonly array $dataColumnNames;

    /** PHP property name corresponding to the PK column. Equals `$pk` when no `name:` override is used on the PK column. */
    public readonly string $pkProp;

    /**
     * Non-PK unique keys. Map: key name → ordered list of column names.
     * Property-level keys list members in property-declaration order; class-level
     * keys list members in the order given by the attribute's `columns` parameter.
     *
     * @var array<string, list<string>>
     */
    public readonly array $uniqueKeys;

    /**
     * Non-unique indexes. Map: index name → ordered list of column names.
     * Property-level indexes list members in property-declaration order; class-level
     * indexes list members in the order given by the attribute's `columns` parameter.
     *
     * @var array<string, list<string>>
     */
    public readonly array $indexes;

    /**
     * Foreign-key constraints derived from owning-side #[Relation] attributes
     * (ManyToOne, OneToOne) with `emitFk: true`. Polymorphic and inverse-side
     * relations are skipped.
     *
     * @var list<ForeignKeyDefinition>
     */
    public readonly array $foreignKeys;

    /**
     * Table-level CHECK constraints from class-level #[Check] attributes, in declaration order.
     * Keyed by the *emitted* constraint name, which is what the database and any schema tooling
     * see; the declared name is on the definition.
     *
     * @var array<string, CheckDefinition>
     */
    public readonly array $checks;

    /**
     * Declared renames of indexes and unique keys — current name → {@see RenameDefinition}. One map
     * for both, because the two share a namespace on every engine that has one.
     *
     * **Inert in core**, like every other evolution marker: what a table *was* called has no bearing
     * on reading or writing it, and `CREATE TABLE` never mentions it.
     *
     * @var array<string, RenameDefinition>
     */
    public readonly array $indexRenames;

    /**
     * Schema objects declared **absent** ({@see Absent}), grouped by
     * kind so a name that is legitimately reused across kinds stays unambiguous. Inert in core.
     *
     * @var array<value-of<SchemaObjectKind>, array<string, AbsentDefinition>>
     */
    public readonly array $absent;

    /**
     * Schema objects declared **not ours** ({@see Unmanaged}),
     * grouped by kind: kind → name → true. Inert in core.
     *
     * @var array<value-of<SchemaObjectKind>, array<string, true>>
     */
    public readonly array $unmanaged;

    /**
     * Columns of an {@see Immutable} Record exempted from its promise by
     * {@see MutableAttr} — column name → true. Empty on a Record that
     * is not immutable, where every column is writable anyway.
     *
     * @var array<string, true>
     */
    public readonly array $mutableColumns;

    /**
     * @param array<string, ColumnDefinition>                                    $columns
     * @param array<string, RelationDefinition>                                  $relations
     * @param array<string, \ReflectionProperty>                                 $reflProperties
     * @param array<string, list<string>>                                        $uniqueKeys
     * @param array<string, list<string>>                                        $indexes
     * @param list<ForeignKeyDefinition>                                         $foreignKeys
     * @param array<string, CheckDefinition>                                     $checks
     * @param array<string, RenameDefinition>                                    $indexRenames
     * @param array<value-of<SchemaObjectKind>, array<string, AbsentDefinition>> $absent
     * @param array<value-of<SchemaObjectKind>, array<string, true>>             $unmanaged
     * @param array<string, true>                                                $mutableColumns
     */
    private function __construct(
        public readonly string $tableName,
        public readonly string $pk,
        /**
         * Ordered PK member columns when the table declares a **composite** primary key
         * ({@see PrimaryKeyAttr}), else null. Non-null makes the schema **DDL-only**: `$pk` holds
         * the first member purely to keep internal invariants intact and is not a row identifier,
         * so every CRUD path calls {@see assertSingleColumnPk()} and refuses.
         *
         * @var list<string>|null
         */
        public readonly ?array $compositePk,
        public readonly ?int $lockTier,
        array $columns,
        array $relations,
        array $reflProperties,
        array $uniqueKeys,
        array $indexes,
        array $foreignKeys,
        array $checks,
        array $indexRenames,
        array $absent,
        array $unmanaged,
        array $mutableColumns,
        public readonly ?string $comment,
        public readonly ?MysqlTableOptions $mysqlOptions,
        /** Column name auto-set to now on INSERT ({@see CreatedAt}), or null. */
        public readonly ?string $createdAtColumn = null,
        /** Column name auto-set to now on INSERT + dirty UPDATE ({@see UpdatedAt}), or null. */
        public readonly ?string $updatedAtColumn = null,
        /** Integer column carrying the optimistic-locking version ({@see Version}), or null. */
        public readonly ?string $versionColumn = null,
    ) {
        $this->columns = $columns;
        $this->relations = $relations;
        $this->reflProperties = $reflProperties;
        $this->uniqueKeys = $uniqueKeys;
        $this->indexes = $indexes;
        $this->foreignKeys = $foreignKeys;
        $this->checks = $checks;
        $this->indexRenames = $indexRenames;
        $this->absent = $absent;
        $this->unmanaged = $unmanaged;
        $this->mutableColumns = $mutableColumns;
        $this->dataColumnNames = array_values(
            array_filter(array_keys($columns), fn (string $n): bool => $n !== $pk),
        );
        $this->pkProp = $columns[$pk]->propertyName;
    }

    /**
     * The primary key's member columns, in key order — one entry for an ordinary table, two or
     * more for a composite key. Use this wherever the *whole* key matters (DDL emission); use
     * `$pk` only on paths that have already established the key is single-column.
     *
     * @return list<string>
     */
    public function pkColumns(): array
    {
        return $this->compositePk ?? [$this->pk];
    }

    /**
     * Guard for every path that identifies, writes or locks a row **by primary key**.
     *
     * A composite-PK Record is a table *description*, not a CRUD target: `$pk` holds only the
     * first member, so `find($id)`, a keyed upsert or an ascending-PK lock would each silently
     * address the wrong rows. Throwing names the operation, so the message says what to do
     * instead rather than surfacing as a confusing miss further down.
     *
     * @throws SchemaException when the schema declares a composite primary key
     */
    /**
     * Who writes `$column`, and when — see {@see ColumnRole}.
     *
     * Computed rather than stored: the answer is a handful of comparisons over metadata already
     * held, and precomputing it for every Record would cost every schema in the process to serve
     * the few that ask.
     *
     * @throws SchemaException when the table declares no such column
     */
    public function columnRole(string $column): ColumnRole
    {
        $col = $this->columns[$column]
            ?? throw new SchemaException(sprintf('columnRole(): "%s" is not a declared column of %s.', $column, $this->tableName));

        // Order matters: a column can answer to more than one test, and the earlier ones are the
        // stronger claim. An auto-increment primary key is written by the engine too, but what it
        // *is* is the key.
        return match (true) {
            $column === $this->pk, \in_array($column, $this->compositePk ?? [], true)                                      => ColumnRole::PrimaryKey,
            $col->isGenerated                                                                                              => ColumnRole::Generated,
            \in_array($column, array_filter([$this->createdAtColumn, $this->updatedAtColumn, $this->versionColumn]), true) => ColumnRole::Managed,
            isset($this->mutableColumns[$column])                                                                          => ColumnRole::Exempted,
            default                                                                                                        => ColumnRole::Content,
        };
    }

    /**
     * The columns filling any of `$roles`, in declaration order, keyed by column name.
     *
     * `columnsByRole(ColumnRole::Content)` is the whole of what a content digest should hash.
     *
     * @return array<string, ColumnDefinition>
     *
     * @throws SchemaException when no role is named — asking for nothing is a mistake, not a query
     */
    public function columnsByRole(ColumnRole ...$roles): array
    {
        if ([] === $roles) {
            throw new SchemaException('columnsByRole(): name at least one role.');
        }

        $wanted = array_fill_keys(array_map(static fn (ColumnRole $r): string => $r->value, $roles), true);

        $out = [];
        foreach ($this->columns as $name => $col) {
            if (isset($wanted[$this->columnRole($name)->value])) {
                $out[$name] = $col;
            }
        }

        return $out;
    }

    public function assertSingleColumnPk(string $operation): void
    {
        if (null === $this->compositePk) {
            return;
        }

        throw new SchemaException(sprintf(
            '%s: %s is not available on a Record with a composite primary key (%s). '
            .'#[PrimaryKey(columns: ...)] declares a table for DDL and schema-evolution tooling only; '
            .'read and write it with raw SQL, or give the table a single-column key.',
            $this->tableName,
            $operation,
            implode(', ', $this->compositePk),
        ));
    }

    /** @var array<string, true>|null memoized: the set of assignable column property names */
    private ?array $_columnProperties = null;

    /**
     * The property names backing this table's columns, as a set for O(1) membership tests.
     *
     * Note these are **property** names, not column names — they differ wherever a column
     * declares a `name:` override. Callers assigning from an array (see {@see Record::set()})
     * address properties, so this is the set they must be checked against.
     *
     * @return array<string, true>
     */
    public function columnProperties(): array
    {
        if (null !== $this->_columnProperties) {
            return $this->_columnProperties;
        }

        $names = [];
        foreach ($this->columns as $col) {
            $names[$col->propertyName] = true;
        }

        return $this->_columnProperties = $names;
    }

    /** @var array<string, list<string>>|null memoized: generated column → the columns its expression references */
    private ?array $_generatedDeps = null;

    /**
     * Map each generated column to the schema columns its `generatedAs` expression references. Rather
     * than parse SQL, scan the expression for the (finite, known) column names as identifier tokens —
     * over-inclusion is safe (a spurious dep just reads a column back needlessly), a *miss* would
     * leave a stale value, so the match is deliberately generous.
     *
     * @return array<string, list<string>>
     */
    private function generatedDeps(): array
    {
        if (null !== $this->_generatedDeps) {
            return $this->_generatedDeps;
        }

        $names = array_keys($this->columns);
        $deps = [];
        foreach ($this->columns as $name => $col) {
            if (!$col->isGenerated || null === $col->generatedAs) {
                continue;
            }
            $expr = $col->generatedAs;
            $refs = [];
            foreach ($names as $cand) {
                if ($cand === $name) {
                    continue;
                }
                if (1 === preg_match('/(?<![A-Za-z0-9_])'.preg_quote($cand, '/').'(?![A-Za-z0-9_])/i', $expr)) {
                    $refs[] = $cand;
                }
            }
            $deps[$name] = $refs;
        }

        return $this->_generatedDeps = $deps;
    }

    /**
     * Given the columns a write actually wrote, return the generated columns whose value may have
     * changed — those whose expression references a written column, transitively through other
     * generated columns. On INSERT every non-generated column is written, so all generated columns
     * are returned; on UPDATE only the ones a changed column feeds into.
     *
     * @param array<string, true> $writtenCols
     *
     * @return list<string>
     */
    public function generatedColumnsAffectedBy(array $writtenCols): array
    {
        $deps = $this->generatedDeps();
        if ([] === $deps) {
            return [];
        }

        $affected = [];
        do {
            $changed = false;
            foreach ($deps as $gen => $srcs) {
                if (isset($affected[$gen])) {
                    continue;
                }
                foreach ($srcs as $src) {
                    if (isset($writtenCols[$src]) || isset($affected[$src])) {
                        $affected[$gen] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return array_keys($affected);
    }

    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * Build (or return cached) schema for the given Record subclass.
     *
     * @param class-string $class
     *
     * @throws SchemaException
     */
    public static function fromClass(string $class): self
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $reflClass = new \ReflectionClass($class);

        // --- #[Table] ---
        $tableAttrs = $reflClass->getAttributes(Table::class);
        if (empty($tableAttrs)) {
            throw new SchemaException(sprintf('%s must declare #[Table(name: ...)] attribute.', $class));
        }
        $tableAttr = $tableAttrs[0]->newInstance();
        $tableName = \Nandan108\Attrecord\Record::tablePrefix().$tableAttr->name;
        $pk = $tableAttr->primaryKey;

        // --- #[PrimaryKey(columns:)] — composite, DDL-only ---
        $compositePk = null;
        $pkAttrs = $reflClass->getAttributes(PrimaryKeyAttr::class);
        if ([] !== $pkAttrs) {
            $compositePk = $pkAttrs[0]->newInstance()->columns;
            if (\count($compositePk) < 2) {
                throw new SchemaException(sprintf(
                    '%s: #[PrimaryKey] needs at least two columns (got %d); a single-column key is #[Table(primaryKey: ...)].',
                    $class,
                    \count($compositePk),
                ));
            }
            if (\count($compositePk) !== \count(array_unique($compositePk))) {
                throw new SchemaException(sprintf('%s: #[PrimaryKey] lists a column more than once.', $class));
            }
            // A #[Table(primaryKey:)] left at its "id" default is not a deliberate contradiction;
            // an explicit one is, and silently letting the composite win would hide the mistake.
            if ('id' !== $tableAttr->primaryKey) {
                throw new SchemaException(sprintf(
                    '%s declares both #[PrimaryKey(columns: ...)] and #[Table(primaryKey: "%s")]; use one.',
                    $class,
                    $tableAttr->primaryKey,
                ));
            }
            // `pk` stays a single string for every internal invariant that depends on it
            // (pkProp, dataColumnNames). It is the first member and is *never* a valid row
            // identifier here — which is why every CRUD path refuses this schema outright.
            $pk = $compositePk[0];
        }

        // --- #[LockTier] ---
        $lockTierAttrs = $reflClass->getAttributes(LockTier::class);
        $lockTier = empty($lockTierAttrs) ? null : $lockTierAttrs[0]->newInstance()->tier;

        // --- Properties: columns and relations, plus property-level keys/indexes ---
        /** @var array<string, ColumnDefinition> $columns */
        $columns = [];
        $createdAtColumn = null;
        $updatedAtColumn = null;
        $versionColumn = null;
        /** @var array<string, RelationDefinition> $relations */
        $relations = [];
        /** @var array<string, \ReflectionProperty> $reflProperties */
        $reflProperties = [];
        /** @var array<string, list<string>> $uniqueKeys */
        $uniqueKeys = [];
        /** @var array<string, list<string>> $indexes */
        $indexes = [];
        /** @var array<string, true> $uniqueKeysFromProperty   key-name → true (origin tracking) */
        $uniqueKeysFromProperty = [];
        /** @var array<string, true> $indexesFromProperty */
        $indexesFromProperty = [];
        /** @var array<string, RenameDefinition> $indexRenames  current name → what it was called before */
        $indexRenames = [];
        /** @var array<string, true> $mutableColumns  columns exempted from an Immutable row's promise */
        $mutableColumns = [];

        foreach ($reflClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $colAttrs = $prop->getAttributes(Column::class);
            if (!empty($colAttrs)) {
                $colAttr = $colAttrs[0]->newInstance();
                $propName = $prop->getName();
                $colName = $colAttr->name ?? $propName;

                self::validateColumnAttribute($class, $propName, $colAttr);

                if (isset($columns[$colName])) {
                    throw new SchemaException(sprintf(
                        '%s::$%s: column name "%s" is already used by another #[Column] property on the same class.',
                        $class,
                        $propName,
                        $colName,
                    ));
                }

                $ukNames = [];
                foreach ($prop->getAttributes(UniqueKey::class) as $ukAttrRefl) {
                    $ukAttr = $ukAttrRefl->newInstance();
                    if (null !== $ukAttr->columns) {
                        throw new SchemaException(sprintf(
                            '%s::$%s: #[UniqueKey(\'%s\')] at property level must not specify the `columns` parameter (use class-level form for explicit column lists).',
                            $class,
                            $propName,
                            $ukAttr->name,
                        ));
                    }
                    $ukNames[] = $ukAttr->name;
                    $uniqueKeys[$ukAttr->name][] = $colName;
                    $uniqueKeysFromProperty[$ukAttr->name] = true;
                    self::recordRename($class, $indexRenames, $ukAttr->name, $ukAttr->renamedFrom, $ukAttr->renamedSince);
                }

                if ([] !== $prop->getAttributes(MutableAttr::class)) {
                    $mutableColumns[$colName] = true;
                }

                $ixNames = [];
                foreach ($prop->getAttributes(Index::class) as $ixAttrRefl) {
                    $ixAttr = $ixAttrRefl->newInstance();
                    if (null !== $ixAttr->columns) {
                        throw new SchemaException(sprintf(
                            '%s::$%s: #[Index(\'%s\')] at property level must not specify the `columns` parameter (use class-level form for explicit column lists).',
                            $class,
                            $propName,
                            $ixAttr->name,
                        ));
                    }
                    $ixNames[] = $ixAttr->name;
                    $indexes[$ixAttr->name][] = $colName;
                    $indexesFromProperty[$ixAttr->name] = true;
                    self::recordRename($class, $indexRenames, $ixAttr->name, $ixAttr->renamedFrom, $ixAttr->renamedSince);
                }

                $propReflType = $prop->getType();
                $phpType = $propReflType instanceof \ReflectionNamedType ? $propReflType->getName() : null;

                // Resolve the column caster: an explicit #[Cast]-family attribute wins;
                // otherwise a JsonCaster is auto-attached to an array-typed Json column.
                // Filter by class hierarchy on the class-string (no newInstance on non-casters).
                $castAttrs = array_values(array_filter(
                    $prop->getAttributes(),
                    static fn (\ReflectionAttribute $a): bool => is_a($a->getName(), Cast::class, true),
                ));
                if (\count($castAttrs) > 1) {
                    throw new SchemaException(sprintf(
                        '%s::$%s: at most one caster attribute is allowed.',
                        $class,
                        $propName,
                    ));
                }
                $isJsonCol = ColumnType::Json === $colAttr->type;
                $autoJson = $isJsonCol && (
                    'array' === $phpType
                    || (null !== $phpType && is_a($phpType, JsonCastable::class, true))
                );
                $caster = match (true) {
                    [] !== $castAttrs => $castAttrs[0]->newInstance(),
                    $autoJson         => new JsonCaster(),
                    default           => null,
                };
                \assert(null === $caster || $caster instanceof ColumnCaster);
                if (null !== $caster && ($colAttr->autoIncrement || null !== $colAttr->generatedAs)) {
                    throw new SchemaException(sprintf(
                        '%s::$%s: a caster cannot be applied to an autoIncrement or generated column.',
                        $class,
                        $propName,
                    ));
                }

                // An Enum column with an #[EnumCaster] (or a Set column with a #[SetCaster]) and no
                // explicit `enumValues:` derives its allowed-value list from the enum's cases — the
                // caster already names the enum, so the inline list would just duplicate it (and could
                // drift out of sync).
                $enumValues = $colAttr->enumValues;
                if (null === $enumValues && (
                    (ColumnType::Enum === $colAttr->type && $caster instanceof EnumCaster)
                    || (ColumnType::Set === $colAttr->type && $caster instanceof SetCaster)
                )) {
                    $enumValues = $caster->enumValues();
                }

                $col = new ColumnDefinition(
                    name: $colName,
                    propertyName: $propName,
                    type: $colAttr->type,
                    phpType: $phpType,
                    caster: $caster,
                    nullable: $colAttr->nullable,
                    autoIncrement: $colAttr->autoIncrement,
                    trimOnSave: $colAttr->trimOnSave,
                    length: $colAttr->length,
                    precision: $colAttr->precision,
                    scale: $colAttr->scale,
                    uniqueKeyNames: $ukNames,
                    indexNames: $ixNames,
                    // A backed-enum default is unwrapped here, at the single point where the
                    // attribute becomes a definition, so ColumnDefinition and every dialect keep
                    // dealing in scalars only.
                    default: $colAttr->default instanceof \BackedEnum
                        ? $colAttr->default->value
                        : $colAttr->default,
                    defaultExpr: $colAttr->defaultExpr,
                    onUpdate: $colAttr->onUpdate,
                    comment: $colAttr->comment,
                    enumValues: $enumValues,
                    generatedAs: $colAttr->generatedAs,
                    generatedMode: null !== $colAttr->generatedAs
                        ? ($colAttr->generatedMode ?? GeneratedColumnMode::Stored)
                        : null,
                    renamedFrom: $colAttr->renamedFrom,
                    renamedSince: $colAttr->renamedSince,
                );

                if (true === $colAttr->trimOnSave && !$col->isString) {
                    throw new SchemaException(
                        "{$class}::\${$propName}: trimOnSave is only valid for string column types.",
                    );
                }

                if ((ColumnType::Enum === $col->type || ColumnType::Set === $col->type)
                    && (null === $col->enumValues || [] === $col->enumValues)
                ) {
                    throw new SchemaException(
                        "{$class}::\${$propName}: #[Column(ColumnType::{$col->type->name})] requires a non-empty "
                        .'`enumValues` list (or, on an Enum column, an #[EnumCaster] to derive it from the enum\'s cases).',
                    );
                }

                foreach ([[CreatedAt::class, 'created'], [UpdatedAt::class, 'updated']] as [$attrClass, $kind]) {
                    if (empty($prop->getAttributes($attrClass))) {
                        continue;
                    }
                    if (ColumnType::DateTime !== $col->type && ColumnType::Timestamp !== $col->type) {
                        throw new SchemaException(sprintf(
                            '%s::$%s: #[%s] requires a DateTime or Timestamp column.',
                            $class,
                            $propName,
                            'created' === $kind ? 'CreatedAt' : 'UpdatedAt',
                        ));
                    }
                    if ('created' === $kind) {
                        null === $createdAtColumn || throw new SchemaException(sprintf('%s: at most one #[CreatedAt] column (already on "%s").', $class, $createdAtColumn));
                        $createdAtColumn = $colName;
                    } else {
                        null === $updatedAtColumn || throw new SchemaException(sprintf('%s: at most one #[UpdatedAt] column (already on "%s").', $class, $updatedAtColumn));
                        $updatedAtColumn = $colName;
                    }
                }

                if (!empty($prop->getAttributes(Version::class))) {
                    if (!$col->isInteger) {
                        throw new SchemaException(sprintf(
                            '%s::$%s: #[Version] requires an integer column (the version is incremented on every UPDATE).',
                            $class,
                            $propName,
                        ));
                    }
                    if ($col->isGenerated) {
                        throw new SchemaException(sprintf(
                            '%s::$%s: #[Version] cannot be a generated column — attrecord increments it itself.',
                            $class,
                            $propName,
                        ));
                    }
                    null === $versionColumn || throw new SchemaException(sprintf('%s: at most one #[Version] column (already on "%s").', $class, $versionColumn));
                    $versionColumn = $colName;
                }

                $columns[$colName] = $col;
                $reflProperties[$colName] = $prop;
                continue;
            }

            $relAttrs = $prop->getAttributes(Relation::class);
            if (!empty($relAttrs)) {
                $relAttr = $relAttrs[0]->newInstance();
                $propName = $prop->getName();

                self::validateRelationAttribute($class, $propName, $relAttr);

                $relations[$propName] = new RelationDefinition(
                    propertyName: $propName,
                    type: $relAttr->type,
                    targetClass: $relAttr->class,
                    foreignKey: $relAttr->foreignKey,
                    localKey: $relAttr->localKey,
                    morphType: $relAttr->morphType,
                    morphKey: $relAttr->morphKey,
                    morphValue: $relAttr->morphValue,
                    morphMap: $relAttr->morphMap,
                    pivotTable: $relAttr->pivotTable,
                    pivotLocalKey: $relAttr->pivotLocalKey,
                    pivotForeignKey: $relAttr->pivotForeignKey,
                    throughClass: $relAttr->through,
                    secondKey: $relAttr->secondKey,
                    throughKey: $relAttr->throughKey,
                );
            }
        }

        if (!isset($columns[$pk])) {
            throw new SchemaException(
                sprintf(
                    '%s declares primaryKey="%s" but no #[Column] with that column name exists.',
                    $class,
                    $pk,
                ),
            );
        }

        if (null !== $compositePk) {
            foreach ($compositePk as $pkCol) {
                if (!isset($columns[$pkCol])) {
                    throw new SchemaException(sprintf(
                        '%s: #[PrimaryKey] names "%s", but no #[Column] with that column name exists.',
                        $class,
                        $pkCol,
                    ));
                }
                if ($columns[$pkCol]->autoIncrement) {
                    throw new SchemaException(sprintf(
                        '%s: #[PrimaryKey] member "%s" is auto-increment; no engine allows that in a composite key.',
                        $class,
                        $pkCol,
                    ));
                }
            }
        }

        // --- Class-level #[UniqueKey] ---
        foreach ($reflClass->getAttributes(UniqueKey::class) as $ukAttrRefl) {
            $ukAttr = $ukAttrRefl->newInstance();
            if (null === $ukAttr->columns || [] === $ukAttr->columns) {
                throw new SchemaException(sprintf(
                    '%s: #[UniqueKey(\'%s\')] at class level requires a non-empty `columns` list.',
                    $class,
                    $ukAttr->name,
                ));
            }
            if (isset($uniqueKeysFromProperty[$ukAttr->name]) || isset($uniqueKeys[$ukAttr->name])) {
                throw new SchemaException(sprintf(
                    '%s: unique key "%s" is declared both at class level and at property level; pick one form.',
                    $class,
                    $ukAttr->name,
                ));
            }
            foreach ($ukAttr->columns as $colName) {
                if (!isset($columns[$colName])) {
                    throw new SchemaException(sprintf(
                        '%s: #[UniqueKey(\'%s\')] references column "%s" which is not a declared #[Column].',
                        $class,
                        $ukAttr->name,
                        $colName,
                    ));
                }
            }
            $uniqueKeys[$ukAttr->name] = $ukAttr->columns;
            self::recordRename($class, $indexRenames, $ukAttr->name, $ukAttr->renamedFrom, $ukAttr->renamedSince);
        }

        // --- Class-level #[Index] ---
        foreach ($reflClass->getAttributes(Index::class) as $ixAttrRefl) {
            $ixAttr = $ixAttrRefl->newInstance();
            if (null === $ixAttr->columns || [] === $ixAttr->columns) {
                throw new SchemaException(sprintf(
                    '%s: #[Index(\'%s\')] at class level requires a non-empty `columns` list.',
                    $class,
                    $ixAttr->name,
                ));
            }
            if (isset($indexesFromProperty[$ixAttr->name]) || isset($indexes[$ixAttr->name])) {
                throw new SchemaException(sprintf(
                    '%s: index "%s" is declared both at class level and at property level; pick one form.',
                    $class,
                    $ixAttr->name,
                ));
            }
            foreach ($ixAttr->columns as $colName) {
                if (!isset($columns[$colName])) {
                    throw new SchemaException(sprintf(
                        '%s: #[Index(\'%s\')] references column "%s" which is not a declared #[Column].',
                        $class,
                        $ixAttr->name,
                        $colName,
                    ));
                }
            }
            $indexes[$ixAttr->name] = $ixAttr->columns;
            self::recordRename($class, $indexRenames, $ixAttr->name, $ixAttr->renamedFrom, $ixAttr->renamedSince);
        }

        // --- Class-level #[Check] ---
        $checks = self::collectChecks($class, $reflClass, $tableName, \Nandan108\Attrecord\Record::tablePrefix(), $columns);

        self::assertMutableColumnsAreMeaningful($class, $mutableColumns, $columns, $pk, [$createdAtColumn, $updatedAtColumn, $versionColumn]);

        // --- Foreign keys from owning-side relations ---
        $foreignKeys = self::collectForeignKeys($class, $tableName, \Nandan108\Attrecord\Record::tablePrefix(), $relations, $columns);

        // --- Class-level #[Absent] --- last, so it can be checked against everything declared present
        [$absent, $unmanaged] = self::collectExclusions($class, $reflClass, $columns, $uniqueKeys, $indexes, $checks, $foreignKeys);

        // --- Dialect-specific options (read by the matching dialect only) ---
        $mysqlOptionsAttrs = $reflClass->getAttributes(MysqlTableOptions::class);
        $mysqlOptions = empty($mysqlOptionsAttrs) ? null : $mysqlOptionsAttrs[0]->newInstance();

        return self::$cache[$class] = new self(
            tableName: $tableName,
            pk: $pk,
            compositePk: $compositePk,
            lockTier: $lockTier,
            columns: $columns,
            relations: $relations,
            reflProperties: $reflProperties,
            uniqueKeys: $uniqueKeys,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            checks: $checks,
            indexRenames: $indexRenames,
            absent: $absent,
            unmanaged: $unmanaged,
            mutableColumns: $mutableColumns,
            comment: $tableAttr->comment,
            mysqlOptions: $mysqlOptions,
            createdAtColumn: $createdAtColumn,
            updatedAtColumn: $updatedAtColumn,
            versionColumn: $versionColumn,
        );
    }

    /**
     * Validate a #[Column] attribute at schema-build time.
     */
    private static function validateColumnAttribute(string $class, string $propName, Column $col): void
    {
        $loc = "{$class}::\${$propName}";

        if (null !== $col->default && null !== $col->defaultExpr) {
            throw new SchemaException(
                "{$loc}: #[Column] cannot set both `default` and `defaultExpr` (they are mutually exclusive).",
            );
        }

        $needsLength = ColumnType::VarChar === $col->type
            || ColumnType::Char === $col->type
            || ColumnType::VarBinary === $col->type
            || ColumnType::Binary === $col->type;

        if ($needsLength && null === $col->length) {
            throw new SchemaException(
                "{$loc}: #[Column(ColumnType::{$col->type->name})] requires `length`.",
            );
        }

        $acceptsFractionalSeconds = ColumnType::DateTime === $col->type
            || ColumnType::Timestamp === $col->type;

        if (ColumnType::Decimal === $col->type) {
            if (null === $col->precision || null === $col->scale) {
                throw new SchemaException(
                    "{$loc}: #[Column(ColumnType::Decimal)] requires both `precision` and `scale`.",
                );
            }
        } elseif ($acceptsFractionalSeconds) {
            if (null !== $col->precision && ($col->precision < 0 || $col->precision > 6)) {
                throw new SchemaException(
                    "{$loc}: #[Column(ColumnType::{$col->type->name})] precision must be between 0 and 6 (fractional-seconds), got {$col->precision}.",
                );
            }
            if (null !== $col->scale) {
                throw new SchemaException(
                    "{$loc}: #[Column(ColumnType::{$col->type->name})] does not accept `scale` (only Decimal does).",
                );
            }
        } else {
            // Any other type: precision and scale are both meaningless and a likely user mistake.
            if (null !== $col->precision) {
                throw new SchemaException(
                    "{$loc}: #[Column(ColumnType::{$col->type->name})] does not accept `precision` (only Decimal/DateTime/Timestamp do).",
                );
            }
            if (null !== $col->scale) {
                throw new SchemaException(
                    "{$loc}: #[Column(ColumnType::{$col->type->name})] does not accept `scale` (only Decimal does).",
                );
            }
        }

        // Enum/Set `enumValues` non-emptiness is validated on the *derived* ColumnDefinition (see
        // buildColumns), so an #[EnumCaster] on an Enum column may supply the list in place of an
        // inline `enumValues:`.

        // Generated columns (GENERATED ALWAYS AS (...) STORED/VIRTUAL) are computed by
        // the database, so application-side writes (DEFAULT, ON UPDATE, AUTO_INCREMENT)
        // are forbidden by both MySQL and our INSERT/UPDATE skip logic.
        if (null !== $col->generatedAs) {
            if ('' === trim($col->generatedAs)) {
                throw new SchemaException(
                    "{$loc}: #[Column] `generatedAs` must be a non-empty SQL expression.",
                );
            }
            if (null !== $col->default || null !== $col->defaultExpr) {
                throw new SchemaException(
                    "{$loc}: a generated column cannot also declare `default` or `defaultExpr`.",
                );
            }
            if (null !== $col->onUpdate) {
                throw new SchemaException(
                    "{$loc}: a generated column cannot declare `onUpdate`.",
                );
            }
            if ($col->autoIncrement) {
                throw new SchemaException(
                    "{$loc}: a generated column cannot also be `autoIncrement`.",
                );
            }
        } elseif (null !== $col->generatedMode) {
            throw new SchemaException(
                "{$loc}: #[Column] `generatedMode` requires `generatedAs` to be set.",
            );
        }
    }

    /**
     * Name a foreign-key constraint uniquely per *install*, within the 64-character identifier limit.
     *
     * InnoDB scopes constraint names **per database, not per table**, so two installs sharing one
     * database — a PrestaShop→WooCommerce cutover running both hosts against it, or two WordPress
     * sites at `wp_` and `blog_` on shared hosting — must not derive the same name for the same
     * logical table. The table prefix is the only thing distinguishing them, so it has to survive
     * into the name.
     *
     * It cannot survive *verbatim*: a long prefix would push the name past 64 characters. Instead a
     * **fixed-width digest** of the prefix goes in, which keeps the total independent of how long
     * the prefix is — the property a raw prefix lacks, and which stripping the prefix (the previous
     * behaviour) lacked in the other direction by making distinct installs collide.
     *
     * Six hex characters is ample: this distinguishes installs on one machine, not adversaries.
     *
     * The natural form is `fk_<digest>_<table>_<column>`. Long names still overflow — InvFlux's own
     * `invflux_subject_stock_management_events.reconciliation_run_id` reaches 71 — so past the limit
     * the *column* is folded into a digest and the table name kept, that being the more useful half
     * when reading an error message. The result is deterministic and always within the limit.
     */
    private static function foreignKeyConstraintName(string $tablePrefix, string $tableName, string $column): string
    {
        $logical = '' !== $tablePrefix && str_starts_with($tableName, $tablePrefix)
            ? substr($tableName, \strlen($tablePrefix))
            : $tableName;

        $head = 'fk_';
        if ('' !== $tablePrefix) {
            $head .= substr(hash('sha256', $tablePrefix), 0, 6).'_';
        }

        $name = $head.$logical.'_'.$column;
        if (\strlen($name) <= self::MAX_IDENTIFIER_LENGTH) {
            return $name;
        }

        $digest = substr(hash('sha256', $logical.'.'.$column), 0, 10);
        $room = self::MAX_IDENTIFIER_LENGTH - \strlen($head) - 1 - \strlen($digest);

        return $head.substr($logical, 0, max(0, $room)).'_'.$digest;
    }

    /**
     * Name a CHECK constraint uniquely per *install*, per *table* and per *expression*.
     *
     * MySQL scopes CHECK constraint names **per database** (`ERROR 3822 Duplicate check constraint
     * name`) where every other supported engine scopes them per table. So everything that
     * distinguishes one declaration from another has to survive into the name, and two things do:
     *
     * - **The scope digest** covers the table prefix *and* the table. The prefix half is the
     *   foreign-key story — two installs sharing one database must not derive the same name (see
     *   {@see foreignKeyConstraintName()} for why a digest rather than the prefix itself). The
     *   table half is what the foreign-key case gets for free by carrying the table name in the
     *   clear: without it, the same rule written on two tables — `#[Check('qty_non_negative',
     *   'qty >= 0')]` on an order line and on a purchase-order line — collides inside a single
     *   install. Digested rather than spelled out so the name stays within the identifier limit for
     *   any table name, at the cost of the table not being readable in a violation message; the
     *   declared rule name, which says what was actually broken, stays legible instead.
     *
     * - **The expression digest** has no foreign-key equivalent. No engine gives the expression
     *   back the way it was written: MySQL re-prints it with charset introducers and brackets of
     *   its own, PostgreSQL adds casts. Comparing a live expression to a declared one therefore
     *   cannot distinguish "the author changed the rule" from "the engine spells it differently",
     *   and the fail-safe reading of that ambiguity — assume the engine — silently withholds a
     *   corrected rule from every database that already has the old one. Digesting the expression
     *   into the *name* removes the comparison from the problem: an edited expression is a
     *   differently-named constraint, so name-only convergence adds the new one and drops the old.
     *
     * Whitespace is normalized before digesting the expression, so re-indenting one is not a schema
     * change; nothing else is, so any edit to what it *says* moves the digest.
     */
    private static function checkConstraintName(
        string $tablePrefix,
        string $tableName,
        string $declaredName,
        string $expression,
    ): string {
        $logical = '' !== $tablePrefix && str_starts_with($tableName, $tablePrefix)
            ? substr($tableName, \strlen($tablePrefix))
            : $tableName;

        $head = 'chk_'.substr(hash('sha256', $tablePrefix.'|'.$logical), 0, 6).'_';
        $tail = '_'.substr(hash('sha256', trim((string) preg_replace('/\s+/', ' ', $expression))), 0, 6);

        $room = self::MAX_IDENTIFIER_LENGTH - \strlen($head) - \strlen($tail);

        return $head.substr($declaredName, 0, max(0, $room)).$tail;
    }

    /**
     * Refuse a `#[Mutable]` that cannot mean anything: on a Record that promises nothing, or on a
     * column no update path could write anyway.
     *
     * All three are silent no-ops otherwise, and a marker that reads as a deliberate exemption while
     * exempting nothing is worse than no marker — it tells a reader the column moves when it does not.
     *
     * @param array<string, true>             $mutableColumns
     * @param array<string, ColumnDefinition> $columns
     * @param list<string|null>               $managed        the auto-managed column names, if any
     */
    private static function assertMutableColumnsAreMeaningful(
        string $class,
        array $mutableColumns,
        array $columns,
        string $pk,
        array $managed,
    ): void {
        if ([] === $mutableColumns) {
            return;
        }
        if (!is_a($class, Immutable::class, true)) {
            throw new SchemaException(sprintf(
                '%s: #[Mutable] on "%s" but the Record is not Immutable — every column is already '
                .'writable, so the marker exempts it from nothing.',
                $class,
                array_key_first($mutableColumns),
            ));
        }
        foreach (array_keys($mutableColumns) as $colName) {
            if ($colName === $pk) {
                throw new SchemaException(sprintf(
                    '%s: #[Mutable] on the primary key "%s". attrecord never updates a primary key, '
                    .'and on a content-addressed table the key *is* the identity the row is promising '
                    .'not to change.',
                    $class,
                    $colName,
                ));
            }
            if (isset($columns[$colName]) && $columns[$colName]->isGenerated) {
                throw new SchemaException(sprintf(
                    '%s: #[Mutable] on generated column "%s", which no engine lets anyone write.',
                    $class,
                    $colName,
                ));
            }
            if (\in_array($colName, $managed, true)) {
                throw new SchemaException(sprintf(
                    '%s: #[Mutable] on "%s", which attrecord writes itself (#[CreatedAt] / #[UpdatedAt] / '
                    .'#[Version]). The attribute exempts a column *you* write; here it would be ignored, '
                    .'and silently — see Enum\\ColumnRole::Managed.',
                    $class,
                    $colName,
                ));
            }
        }
    }

    /**
     * Record a declared index/unique-key rename, refusing the ways it can be written wrong.
     *
     * A composite key declared property-by-property repeats its name on every member, so the same
     * rename legitimately arrives several times; agreeing repeats are folded, disagreeing ones are
     * a mistake worth a message rather than a silent last-wins.
     *
     * @param array<string, RenameDefinition> $renames
     */
    private static function recordRename(
        string $class,
        array &$renames,
        string $name,
        ?string $from,
        ?string $since,
    ): void {
        if (null === $from) {
            if (null !== $since) {
                throw new SchemaException(sprintf(
                    '%s: "%s" gives `renamedSince` without `renamedFrom`; the version dates a rename, it does not declare one.',
                    $class,
                    $name,
                ));
            }

            return;
        }
        if ($from === $name) {
            throw new SchemaException(sprintf(
                '%s: "%s" declares `renamedFrom` pointing at itself.',
                $class,
                $name,
            ));
        }
        $existing = $renames[$name] ?? null;
        if (null !== $existing && ($existing->from !== $from || $existing->since !== $since)) {
            throw new SchemaException(sprintf(
                '%s: "%s" declares two different renames ("%s" and "%s"); a composite may repeat the '
                .'same one on each member, but they must agree.',
                $class,
                $name,
                $existing->from,
                $from,
            ));
        }
        $renames[$name] = new RenameDefinition($from, $since);
    }

    /**
     * The names an `#[Absent]` or `#[Unmanaged]` declaration carries, by kind. Both take the same
     * five parameters, each accepting one name or a list of them.
     *
     * @return array<value-of<SchemaObjectKind>, list<string>>
     */
    private static function namesByKind(Absent | Unmanaged $attr): array
    {
        $byKind = [
            SchemaObjectKind::Index->value      => $attr->index,
            SchemaObjectKind::UniqueKey->value  => $attr->uniqueKey,
            SchemaObjectKind::ForeignKey->value => $attr->foreignKey,
            SchemaObjectKind::Check->value      => $attr->check,
            SchemaObjectKind::Column->value     => $attr->column,
        ];

        $out = [];
        foreach ($byKind as $kindValue => $names) {
            $out[$kindValue] = null === $names ? [] : array_map(strval(...), (array) $names);
        }

        /** @psalm-var array<value-of<SchemaObjectKind>, list<string>> $out */
        return $out;
    }

    /**
     * Collect the two class-level markers that name objects the table's *declared* shape does not:
     * `#[Absent]` (must not exist) and `#[Unmanaged]` (exists, but is not ours).
     *
     * Collected together because they share one validation: every name here must be absent from the
     * declared shape and from the other marker. Declaring an object present and absent, or absent
     * and unmanaged, states two things that cannot both hold, and the differ downstream could only
     * resolve that by guessing which half was meant.
     *
     * @param array<string, ColumnDefinition> $columns
     * @param array<string, list<string>>     $uniqueKeys
     * @param array<string, list<string>>     $indexes
     * @param array<string, CheckDefinition>  $checks
     * @param list<ForeignKeyDefinition>      $foreignKeys
     *
     * @return array{array<value-of<SchemaObjectKind>, array<string, AbsentDefinition>>, array<value-of<SchemaObjectKind>, array<string, true>>}
     */
    private static function collectExclusions(
        string $class,
        \ReflectionClass $reflClass,
        array $columns,
        array $uniqueKeys,
        array $indexes,
        array $checks,
        array $foreignKeys,
    ): array {
        $declaredFkNames = [];
        foreach ($foreignKeys as $fk) {
            $declaredFkNames[$fk->constraintName] = true;
        }
        /** @psalm-var array<value-of<SchemaObjectKind>, array<string, true>> $present */
        $present = [
            SchemaObjectKind::Index->value      => array_fill_keys(array_keys($indexes), true),
            SchemaObjectKind::UniqueKey->value  => array_fill_keys(array_keys($uniqueKeys), true),
            SchemaObjectKind::ForeignKey->value => $declaredFkNames,
            SchemaObjectKind::Check->value      => array_fill_keys(array_keys($checks), true),
            SchemaObjectKind::Column->value     => array_fill_keys(array_keys($columns), true),
        ];

        /** @psalm-var array<value-of<SchemaObjectKind>, array<string, AbsentDefinition>> $absent */
        $absent = array_fill_keys(array_keys($present), []);
        /** @psalm-var array<value-of<SchemaObjectKind>, array<string, true>> $unmanaged */
        $unmanaged = array_fill_keys(array_keys($present), []);

        foreach ([Absent::class, Unmanaged::class] as $attrClass) {
            $short = '#['.substr((string) strrchr($attrClass, '\\'), 1).']';
            foreach ($reflClass->getAttributes($attrClass) as $attrRefl) {
                $attr = $attrRefl->newInstance();
                if (!$attr instanceof Absent && !$attr instanceof Unmanaged) {
                    continue; // unreachable: getAttributes() filtered by class
                }
                $named = false;
                foreach (self::namesByKind($attr) as $kindValue => $names) {
                    $kind = SchemaObjectKind::from($kindValue);
                    foreach ($names as $name) {
                        $named = true;
                        if (isset($present[$kindValue][$name])) {
                            throw new SchemaException(sprintf(
                                '%s: %s "%s" is declared both present and %s. Remove one — the two '
                                .'state different things about the same object.',
                                $class,
                                $kind->label(),
                                $name,
                                $short,
                            ));
                        }
                        if (isset($absent[$kindValue][$name]) || isset($unmanaged[$kindValue][$name])) {
                            throw new SchemaException(sprintf(
                                '%s: %s "%s" is named by more than one #[Absent]/#[Unmanaged] declaration.',
                                $class,
                                $kind->label(),
                                $name,
                            ));
                        }
                        if ($attr instanceof Absent) {
                            $absent[$kindValue][$name] = new AbsentDefinition($kind, $name, $attr->since);
                        } else {
                            $unmanaged[$kindValue][$name] = true;
                        }
                    }
                }
                if (!$named) {
                    throw new SchemaException(sprintf(
                        '%s: %s names nothing; give it at least one of index:, uniqueKey:, '
                        .'foreignKey:, check: or column:.',
                        $class,
                        $short,
                    ));
                }
            }
        }

        return [$absent, $unmanaged];
    }

    /**
     * Compile the class-level #[Check] attributes into definitions keyed by emitted name.
     *
     * @param class-string                    $class
     * @param \ReflectionClass<object>        $reflClass
     * @param array<string, ColumnDefinition> $columns
     *
     * @return array<string, CheckDefinition>
     */
    private static function collectChecks(
        string $class,
        \ReflectionClass $reflClass,
        string $tableName,
        string $tablePrefix,
        array $columns,
    ): array {
        $checks = [];
        /** @psalm-var array<string, true> $declared */
        $declared = [];

        foreach ($reflClass->getAttributes(Check::class) as $checkAttrRefl) {
            $checkAttr = $checkAttrRefl->newInstance();
            $name = $checkAttr->name;

            if ('' === trim($name)) {
                throw new SchemaException(sprintf('%s: #[Check] requires a non-empty name.', $class));
            }
            if ('' === trim($checkAttr->expression)) {
                throw new SchemaException(sprintf(
                    '%s: #[Check(\'%s\')] requires a non-empty boolean SQL expression.',
                    $class,
                    $name,
                ));
            }
            if (isset($declared[$name])) {
                throw new SchemaException(sprintf(
                    '%s: CHECK constraint "%s" is declared twice; constraint names must be unique per table.',
                    $class,
                    $name,
                ));
            }

            // The enum-emulation CHECKs that PostgreSQL and SQLite carry for an #[Column] of type
            // Enum/Set are named from the column, and are owned by the column rather than by the
            // author. A #[Check] landing on one of those names would replace a member list with a
            // rule and take the enum's enforcement with it, so it is refused by name.
            foreach ($columns as $colName => $_col) {
                if (ColumnDefinition::enumCheckConstraintName($colName) === $name) {
                    throw new SchemaException(sprintf(
                        '%s: #[Check(\'%s\')] collides with the CHECK constraint that carries column "%s"\'s '
                        .'enum members on PostgreSQL and SQLite. Pick another name.',
                        $class,
                        $name,
                        $colName,
                    ));
                }
            }

            $declared[$name] = true;
            $constraintName = self::checkConstraintName($tablePrefix, $tableName, $name, $checkAttr->expression);
            $checks[$constraintName] = new CheckDefinition($constraintName, $name, $checkAttr->expression);
        }

        return $checks;
    }

    /**
     * Derive FK definitions from owning-side relations (ManyToOne, OneToOne).
     *
     * @param class-string                      $class
     * @param string                            $tablePrefix the live `Record::tablePrefix()`, passed in
     *                                                       rather than inferred from `$tableName`, so the
     *                                                       constraint name can distinguish two installs
     *                                                       sharing one database
     * @param array<string, RelationDefinition> $relations
     * @param array<string, ColumnDefinition>   $columns
     *
     * @return list<ForeignKeyDefinition>
     */
    private static function collectForeignKeys(
        string $class,
        string $tableName,
        string $tablePrefix,
        array $relations,
        array $columns,
    ): array {
        $fks = [];
        $seenColumns = [];

        foreach ($relations as $propName => $rel) {
            $isOwningSide = RelationType::ManyToOne === $rel->type
                || RelationType::OneToOne === $rel->type;
            if (!$isOwningSide) {
                continue;
            }

            // Re-read the attribute to check emitFk + onDelete/onUpdate (not stored in RelationDefinition).
            // The validation in fromClass() already guarantees foreignKey is set for these types.
            $fk = $rel->foreignKey;
            if (null === $fk) {
                continue;
            }
            if (!isset($columns[$fk])) {
                throw new SchemaException(sprintf(
                    '%s::$%s: #[Relation] references foreignKey "%s" which is not a declared #[Column].',
                    $class,
                    $propName,
                    $fk,
                ));
            }
        }

        // Walk attributes directly to access onDelete / onUpdate / emitFk.
        $reflClass = new \ReflectionClass($class);
        foreach ($reflClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $relAttrs = $prop->getAttributes(Relation::class);
            if (empty($relAttrs)) {
                continue;
            }
            $relAttr = $relAttrs[0]->newInstance();

            $isOwningSide = RelationType::ManyToOne === $relAttr->type
                || RelationType::OneToOne === $relAttr->type;
            if (!$isOwningSide || !$relAttr->emitFk) {
                continue;
            }

            $fkColumn = $relAttr->foreignKey;
            $targetClass = $relAttr->class;
            if (null === $fkColumn || null === $targetClass) {
                continue;
            }

            if (isset($seenColumns[$fkColumn])) {
                throw new SchemaException(sprintf(
                    '%s::$%s: foreign-key column "%s" is already used by another #[Relation] on the same class.',
                    $class,
                    $prop->getName(),
                    $fkColumn,
                ));
            }
            $seenColumns[$fkColumn] = true;

            $constraintName = self::foreignKeyConstraintName($tablePrefix, $tableName, $fkColumn);

            /** @var class-string $targetClass */
            $fks[] = new ForeignKeyDefinition(
                constraintName: $constraintName,
                localColumn: $fkColumn,
                onDelete: $relAttr->onDelete,
                onUpdate: $relAttr->onUpdate,
                targetClass: $targetClass,
            );
        }

        // Record-less foreign keys declared via class-level #[ForeignKey] — for FK targets
        // that have no Record class (raw-SQL-owned or external tables).
        foreach ($reflClass->getAttributes(ForeignKey::class) as $fkAttrRefl) {
            $fkAttr = $fkAttrRefl->newInstance();
            $fkColumn = $fkAttr->column;

            if (!isset($columns[$fkColumn])) {
                throw new SchemaException(sprintf(
                    '%s: #[ForeignKey] column "%s" is not a declared #[Column].',
                    $class,
                    $fkColumn,
                ));
            }
            if (isset($seenColumns[$fkColumn])) {
                throw new SchemaException(sprintf(
                    '%s: foreign-key column "%s" is declared by more than one #[Relation]/#[ForeignKey].',
                    $class,
                    $fkColumn,
                ));
            }
            $seenColumns[$fkColumn] = true;

            $constraintName = self::foreignKeyConstraintName($tablePrefix, $tableName, $fkColumn);

            $fks[] = new ForeignKeyDefinition(
                constraintName: $constraintName,
                localColumn: $fkColumn,
                onDelete: $fkAttr->onDelete,
                onUpdate: $fkAttr->onUpdate,
                source: $fkAttr,
            );
        }

        return $fks;
    }

    /**
     * Validate a #[Relation] attribute at schema-build time so mistakes surface immediately.
     *
     * @param class-string $ownerClass
     */
    private static function validateRelationAttribute(
        string $ownerClass,
        string $propName,
        Relation $rel,
    ): void {
        $loc = "{$ownerClass}::\${$propName}";
        $type = $rel->type->name;

        $isMorphParent = RelationType::MorphMany === $rel->type
            || RelationType::MorphOne === $rel->type;
        $isMorphChild = RelationType::MorphTo === $rel->type;
        $isManyToMany = RelationType::ManyToMany === $rel->type;
        $isThrough = RelationType::HasManyThrough === $rel->type;

        if (!$isMorphChild) {
            if (null === $rel->class) {
                throw new SchemaException(
                    "{$loc}: #[Relation({$type})] requires the \"class\" parameter.",
                );
            }
        }

        // Standard relations and HasManyThrough need a foreignKey; ManyToMany uses pivot keys.
        if (!$isMorphParent && !$isMorphChild && !$isManyToMany) {
            if (null === $rel->foreignKey) {
                throw new SchemaException(
                    "{$loc}: #[Relation({$type})] requires the \"foreignKey\" parameter.",
                );
            }
        }

        if ($isManyToMany) {
            if (null === $rel->pivotTable || null === $rel->pivotLocalKey || null === $rel->pivotForeignKey) {
                throw new SchemaException(
                    "{$loc}: #[Relation(ManyToMany)] requires \"pivotTable\", \"pivotLocalKey\", and \"pivotForeignKey\" parameters.",
                );
            }
        }

        if ($isThrough) {
            if (null === $rel->through || null === $rel->secondKey) {
                throw new SchemaException(
                    "{$loc}: #[Relation(HasManyThrough)] requires \"through\" and \"secondKey\" parameters (plus \"foreignKey\").",
                );
            }
        }

        if ($isMorphParent || $isMorphChild) {
            if (null === $rel->morphType || null === $rel->morphKey) {
                throw new SchemaException(
                    "{$loc}: #[Relation({$type})] requires \"morphType\" and \"morphKey\" parameters.",
                );
            }
        }

        if ($isMorphParent && null === $rel->morphValue) {
            throw new SchemaException(
                "{$loc}: #[Relation({$type})] requires the \"morphValue\" parameter.",
            );
        }

        if ($isMorphChild && null === $rel->morphMap) {
            throw new SchemaException(
                "{$loc}: #[Relation(MorphTo)] requires the \"morphMap\" parameter.",
            );
        }
    }

    /** Remove cached schema for a class. Useful in tests that mock entities. */
    public static function clearCache(?string $class = null): void
    {
        if (null === $class) {
            self::$cache = [];
        } else {
            unset(self::$cache[$class]);
        }
    }

    public function column(string $name): ColumnDefinition
    {
        return $this->columns[$name]
            ?? throw new SchemaException(sprintf('Unknown column "%s".', $name));
    }

    /**
     * Resolve a column name to its corresponding PHP property name.
     *
     * Use this on any code path that has a column name in hand (typically from
     * a #[Relation] attribute or schema field) and needs to access the value
     * on a Record instance via PHP property syntax.
     */
    public function propFor(string $columnName): string
    {
        return $this->columns[$columnName]?->propertyName
            ?? throw new SchemaException(sprintf('Unknown column "%s".', $columnName));
    }

    /** All column names including the PK. */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    /**
     * Derive a copy of this schema carrying extra columns, indexes and unique keys that the
     * Record class cannot declare — because the set is only known at runtime.
     *
     * The motivating shape is a table whose columns are partly *computed*: a slot registry with a
     * column per registered dimension, a plugin's extension columns, an EAV-ish sidecar. A class
     * cannot express those, and the alternative — hand-written `ALTER TABLE` run at boot — is a
     * second, invisible source of DDL that no schema tooling can see or verify.
     *
     * Derivation rather than construction from scratch is deliberate. The Record stays the single
     * source of truth for everything static (types, keys, foreign keys, the primary key), and the
     * result is a normal schema: it keeps the class's reflection data, so nothing downstream has
     * to cope with a class-less `TableSchema`. Only the added columns lack a `ReflectionProperty`,
     * which matters solely to the CRUD paths — and a computed column is not one a Record instance
     * reads or writes by property anyway.
     *
     *     $schema = TableSchema::fromClass(SlotSpace::class)->extendedWith(
     *         columns: ['dim_loc' => new ColumnDefinition(name: 'dim_loc', …)],
     *         indexes: ['idx_dim_loc' => ['active', 'dim_loc', 'id']],
     *     );
     *
     * Adding a column that is already declared is a mistake worth catching: the caller believes it
     * is contributing something the class does not have, and silently winning or losing that
     * collision would be equally confusing.
     *
     * @param array<string, ColumnDefinition> $columns    column name → definition
     * @param array<string, list<string>>     $indexes    index name → ordered column names
     * @param array<string, list<string>>     $uniqueKeys key name → ordered column names
     *
     * @throws SchemaException when an added name collides with a declared one
     */
    public function extendedWith(array $columns = [], array $indexes = [], array $uniqueKeys = []): self
    {
        foreach ([
            ['column', $columns, $this->columns],
            ['index', $indexes, $this->indexes],
            ['unique key', $uniqueKeys, $this->uniqueKeys],
        ] as [$what, $added, $declared]) {
            foreach (array_keys($added) as $name) {
                if (isset($declared[$name])) {
                    throw new SchemaException(sprintf(
                        '%s: cannot add %s "%s" — it is already declared on the Record.',
                        $this->tableName,
                        $what,
                        $name,
                    ));
                }
            }
        }

        // Named arguments, not positional: this call sits far from the constructor, and a
        // positionally-passed list silently re-aims every argument after any new parameter.
        return new self(
            tableName: $this->tableName,
            pk: $this->pk,
            compositePk: $this->compositePk,
            lockTier: $this->lockTier,
            columns: [...$this->columns, ...$columns],
            relations: $this->relations,
            reflProperties: $this->reflProperties,
            uniqueKeys: [...$this->uniqueKeys, ...$uniqueKeys],
            indexes: [...$this->indexes, ...$indexes],
            foreignKeys: $this->foreignKeys,
            checks: $this->checks,
            indexRenames: $this->indexRenames,
            absent: $this->absent,
            unmanaged: $this->unmanaged,
            mutableColumns: $this->mutableColumns,
            comment: $this->comment,
            mysqlOptions: $this->mysqlOptions,
            createdAtColumn: $this->createdAtColumn,
            updatedAtColumn: $this->updatedAtColumn,
            versionColumn: $this->versionColumn,
        );
    }
}
