<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Enum\RelationType;

/**
 * Compiled, cached description of a single relation property.
 *
 * @api
 */
final readonly class RelationDefinition
{
    /**
     * @param string       $propertyName Property name on the owning class
     * @param class-string $targetClass  Fully-qualified target Record subclass
     * @param string       $foreignKey   FK column name on the related table
     * @param string|null  $localKey     Local join key; null = use this table's PK
     */
    public function __construct(
        public string $propertyName,
        public RelationType $type,
        public string $targetClass,
        public string $foreignKey,
        public ?string $localKey,
    ) {
    }
}
