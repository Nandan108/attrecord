<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

use Nandan108\Attrecord\Enum\RelationType;

/**
 * Marks a public property as a relation to another Record class.
 *
 * Standard relations (OneToMany, ManyToOne, OneToOne, OneToOneReversed):
 *   - `class` and `foreignKey` are required.
 *
 * Polymorphic parent (MorphMany, MorphOne):
 *   - `class`, `morphType`, `morphKey`, and `morphValue` are required.
 *   - `morphType` is the type-discriminator column on the related table.
 *   - `morphKey` is the FK column on the related table pointing to this record's PK.
 *   - `morphValue` is the discriminator value that identifies this class (string or int).
 *
 * Polymorphic child (MorphTo):
 *   - `morphType`, `morphKey`, and `morphMap` are required.
 *   - `morphType` and `morphKey` are local columns on this table.
 *   - `morphMap` maps each discriminator value to its target Record class-string.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Relation
{
    /**
     * @param string|null                          $class      Target Record subclass (required for all except MorphTo)
     * @param string|null                          $foreignKey FK column name (required for standard relations)
     * @param string|null                          $localKey   Local join key; defaults to this table's PK
     * @param string|null                          $morphType  Type-discriminator column name
     * @param string|null                          $morphKey   Polymorphic FK column name
     * @param int|string|null                      $morphValue Discriminator value stored in morphType for this class
     * @param array<int|string, class-string>|null $morphMap   Discriminator value → target class map (MorphTo only)
     */
    public function __construct(
        public readonly RelationType $type,
        public readonly ?string $class = null,
        public readonly ?string $foreignKey = null,
        public readonly ?string $localKey = null,
        public readonly ?string $morphType = null,
        public readonly ?string $morphKey = null,
        public readonly int | string | null $morphValue = null,
        public readonly ?array $morphMap = null,
    ) {
    }
}
