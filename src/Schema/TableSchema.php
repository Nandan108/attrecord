<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\MysqlTableOptions;
use Nandan108\Attrecord\Attribute\PrimaryKey as PrimaryKeyAttr;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Attribute\Version;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Caster\JsonCaster;
use Nandan108\Attrecord\Caster\SetCaster;
use Nandan108\Attrecord\ColumnCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\SchemaException;
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
     * @param array<string, ColumnDefinition>    $columns
     * @param array<string, RelationDefinition>  $relations
     * @param array<string, \ReflectionProperty> $reflProperties
     * @param array<string, list<string>>        $uniqueKeys
     * @param array<string, list<string>>        $indexes
     * @param list<ForeignKeyDefinition>         $foreignKeys
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
        }

        // --- Foreign keys from owning-side relations ---
        $foreignKeys = self::collectForeignKeys($class, $tableName, \Nandan108\Attrecord\Record::tablePrefix(), $relations, $columns);

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

            $shortened = (string) preg_replace('/^[a-z0-9]+_/', '', $tableName);
            $constraintName = 'fk_'.('' !== $shortened ? $shortened : $tableName).'_'.$fkColumn;

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
            comment: $this->comment,
            mysqlOptions: $this->mysqlOptions,
            createdAtColumn: $this->createdAtColumn,
            updatedAtColumn: $this->updatedAtColumn,
            versionColumn: $this->versionColumn,
        );
    }
}
