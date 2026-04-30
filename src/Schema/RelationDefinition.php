<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Enum\RelationType;

/**
 * Compiled, cached description of a single relation property.
 *
 * @api
 */
final class RelationDefinition
{
    /**
     * @param string                               $propertyName Property name on the owning class
     * @param string|null                          $targetClass  Fully-qualified target Record subclass (null for MorphTo)
     * @param string|null                          $foreignKey   FK column name on the related table (null for morph relations)
     * @param string|null                          $localKey     Local join key; null = use this table's PK
     * @param string|null                          $morphType    Type-discriminator column name
     * @param string|null                          $morphKey     Polymorphic FK column name
     * @param int|string|null                      $morphValue   Discriminator value for this class (MorphMany/MorphOne)
     * @param array<int|string, class-string>|null $morphMap     Discriminator → class map (MorphTo)
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly RelationType $type,
        public readonly ?string $targetClass,
        public readonly ?string $foreignKey,
        public readonly ?string $localKey,
        public readonly ?string $morphType = null,
        public readonly ?string $morphKey = null,
        public readonly int | string | null $morphValue = null,
        public readonly ?array $morphMap = null,
    ) {
    }
}
