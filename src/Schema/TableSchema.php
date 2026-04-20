<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Exception\SchemaException;

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

    /** @var array<string, \ReflectionProperty>  column name → cached ReflectionProperty */
    public readonly array $reflProperties;

    /** @var list<string> column names excluding the PK */
    public readonly array $dataColumnNames;

    /**
     * @param array<string, ColumnDefinition>    $columns
     * @param array<string, RelationDefinition>  $relations
     * @param array<string, \ReflectionProperty> $reflProperties
     */
    private function __construct(
        public readonly string $tableName,
        public readonly string $primaryKey,
        public readonly ?int $lockTier,
        array $columns,
        array $relations,
        array $reflProperties,
    ) {
        $this->columns = $columns;
        $this->relations = $relations;
        $this->reflProperties = $reflProperties;
        $this->dataColumnNames = array_values(
            array_filter(array_keys($columns), fn (string $n): bool => $n !== $primaryKey),
        );
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
        $tableName = $tableAttr->name;
        $primaryKey = $tableAttr->primaryKey;

        // --- #[LockTier] ---
        $lockTierAttrs = $reflClass->getAttributes(LockTier::class);
        $lockTier = empty($lockTierAttrs) ? null : $lockTierAttrs[0]->newInstance()->tier;

        // --- Properties ---
        /** @var array<string, ColumnDefinition> $columns */
        $columns = [];
        /** @var array<string, RelationDefinition> $relations */
        $relations = [];
        /** @var array<string, \ReflectionProperty> $reflProperties */
        $reflProperties = [];

        foreach ($reflClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $colAttrs = $prop->getAttributes(Column::class);
            if (!empty($colAttrs)) {
                $colAttr = $colAttrs[0]->newInstance();
                $name = $prop->getName();

                $columns[$name] = new ColumnDefinition(
                    name: $name,
                    type: $colAttr->type,
                    nullable: $colAttr->nullable,
                    autoIncrement: $colAttr->autoIncrement,
                    trimOnSet: $colAttr->trimOnSet,
                    length: $colAttr->length,
                    precision: $colAttr->precision,
                    scale: $colAttr->scale,
                );
                $reflProperties[$name] = $prop;
                continue;
            }

            $relAttrs = $prop->getAttributes(Relation::class);
            if (!empty($relAttrs)) {
                $relAttr = $relAttrs[0]->newInstance();
                $name = $prop->getName();
                $relations[$name] = new RelationDefinition(
                    propertyName: $name,
                    type: $relAttr->type,
                    targetClass: $relAttr->class,
                    foreignKey: $relAttr->foreignKey,
                    localKey: $relAttr->localKey,
                );
                $reflProperties[$name] = $prop;
            }
        }

        if (!isset($columns[$primaryKey])) {
            throw new SchemaException(
                sprintf(
                    '%s declares primaryKey="%s" but no #[Column] property with that name exists.',
                    $class,
                    $primaryKey,
                ),
            );
        }

        return self::$cache[$class] = new self(
            tableName: $tableName,
            primaryKey: $primaryKey,
            lockTier: $lockTier,
            columns: $columns,
            relations: $relations,
            reflProperties: $reflProperties,
        );
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

    /** All column names including the PK. */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }
}
