<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Attribute\Cast;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\MysqlTableOptions;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\JsonCaster;
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
        public readonly ?int $lockTier,
        array $columns,
        array $relations,
        array $reflProperties,
        array $uniqueKeys,
        array $indexes,
        array $foreignKeys,
        public readonly ?string $comment,
        public readonly ?MysqlTableOptions $mysqlOptions,
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

        // --- #[LockTier] ---
        $lockTierAttrs = $reflClass->getAttributes(LockTier::class);
        $lockTier = empty($lockTierAttrs) ? null : $lockTierAttrs[0]->newInstance()->tier;

        // --- Properties: columns and relations, plus property-level keys/indexes ---
        /** @var array<string, ColumnDefinition> $columns */
        $columns = [];
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
                    default: $colAttr->default,
                    defaultExpr: $colAttr->defaultExpr,
                    onUpdate: $colAttr->onUpdate,
                    comment: $colAttr->comment,
                    enumValues: $colAttr->enumValues,
                    generatedAs: $colAttr->generatedAs,
                    generatedMode: null !== $colAttr->generatedAs
                        ? ($colAttr->generatedMode ?? GeneratedColumnMode::Stored)
                        : null,
                );

                if (true === $colAttr->trimOnSave && !$col->isString) {
                    throw new SchemaException(
                        "{$class}::\${$propName}: trimOnSave is only valid for string column types.",
                    );
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
        $foreignKeys = self::collectForeignKeys($class, $tableName, $relations, $columns);

        // --- Dialect-specific options (read by the matching dialect only) ---
        $mysqlOptionsAttrs = $reflClass->getAttributes(MysqlTableOptions::class);
        $mysqlOptions = empty($mysqlOptionsAttrs) ? null : $mysqlOptionsAttrs[0]->newInstance();

        return self::$cache[$class] = new self(
            tableName: $tableName,
            pk: $pk,
            lockTier: $lockTier,
            columns: $columns,
            relations: $relations,
            reflProperties: $reflProperties,
            uniqueKeys: $uniqueKeys,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            comment: $tableAttr->comment,
            mysqlOptions: $mysqlOptions,
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

        if (ColumnType::Enum === $col->type || ColumnType::Set === $col->type) {
            if (null === $col->enumValues || [] === $col->enumValues) {
                throw new SchemaException(
                    "{$loc}: #[Column(ColumnType::{$col->type->name})] requires a non-empty `enumValues` list.",
                );
            }
        }

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
     * Derive FK definitions from owning-side relations (ManyToOne, OneToOne).
     *
     * @param class-string                      $class
     * @param array<string, RelationDefinition> $relations
     * @param array<string, ColumnDefinition>   $columns
     *
     * @return list<ForeignKeyDefinition>
     */
    private static function collectForeignKeys(
        string $class,
        string $tableName,
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

            // Strip leading "prefix_" from the table name to keep the
            // constraint name compact when a prefix is in use. Falls back to
            // the full table name when no underscore appears.
            $shortened = (string) preg_replace('/^[a-z0-9]+_/', '', $tableName);
            $constraintName = 'fk_'.('' !== $shortened ? $shortened : $tableName).'_'.$fkColumn;

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

        if (!$isMorphChild) {
            if (null === $rel->class) {
                throw new SchemaException(
                    "{$loc}: #[Relation({$type})] requires the \"class\" parameter.",
                );
            }
        }

        if (!$isMorphParent && !$isMorphChild) {
            if (null === $rel->foreignKey) {
                throw new SchemaException(
                    "{$loc}: #[Relation({$type})] requires the \"foreignKey\" parameter.",
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
}
