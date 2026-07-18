<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

use Nandan108\Attrecord\Enum\ForeignKeyAction;
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
 * Many-to-many (ManyToMany):
 *   - `class`, `pivotTable`, `pivotLocalKey`, and `pivotForeignKey` are required.
 *   - `pivotLocalKey` is the pivot column referencing this record's `localKey` (default PK).
 *   - `pivotForeignKey` is the pivot column referencing the target's PK.
 *
 * Has-many-through (HasManyThrough):
 *   - `class` (far), `through` (intermediate class-string), `foreignKey`, and `secondKey` are required.
 *   - `foreignKey` is the through-table column referencing this record's `localKey` (default PK).
 *   - `secondKey` is the far-table column referencing the through table's `throughKey` (default its PK).
 *
 * Foreign-key constraint emission:
 *   - FK constraints are emitted in CREATE TABLE only for owning-side relations
 *     (ManyToOne, OneToOne). Inverse-side (OneToMany, OneToOneReversed) and
 *     polymorphic relations are always skipped.
 *   - Use `emitFk: false` to opt out of FK constraint emission for a specific
 *     owning-side relation while still using the relation for eager loading.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Relation
{
    /**
     * @param RelationType                         $type            relation kind
     * @param string|null                          $class           Target Record subclass (required for all except MorphTo)
     * @param string|null                          $foreignKey      FK column name (required for standard relations)
     * @param string|null                          $localKey        Local join key; defaults to this table's PK
     * @param string|null                          $morphType       Type-discriminator column name
     * @param string|null                          $morphKey        Polymorphic FK column name
     * @param int|string|null                      $morphValue      Discriminator value stored in morphType for this class
     * @param array<int|string, class-string>|null $morphMap        Discriminator value → target class map (MorphTo only)
     * @param ForeignKeyAction                     $onDelete        FK constraint ON DELETE action (owning-side relations only)
     * @param ForeignKeyAction                     $onUpdate        FK constraint ON UPDATE action (owning-side relations only)
     * @param bool                                 $emitFk          whether to emit a FOREIGN KEY constraint in CREATE TABLE
     * @param string|null                          $pivotTable      ManyToMany: junction table name
     * @param string|null                          $pivotLocalKey   ManyToMany: pivot column → this record's localKey
     * @param string|null                          $pivotForeignKey ManyToMany: pivot column → the target's PK
     * @param class-string|null                    $through         HasManyThrough: intermediate Record class
     * @param string|null                          $secondKey       HasManyThrough: far-table column → the through's throughKey
     * @param string|null                          $throughKey      HasManyThrough: the through table's join key (default its PK)
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
        public readonly ForeignKeyAction $onDelete = ForeignKeyAction::Restrict,
        public readonly ForeignKeyAction $onUpdate = ForeignKeyAction::Restrict,
        public readonly bool $emitFk = true,
        public readonly ?string $pivotTable = null,
        public readonly ?string $pivotLocalKey = null,
        public readonly ?string $pivotForeignKey = null,
        public readonly ?string $through = null,
        public readonly ?string $secondKey = null,
        public readonly ?string $throughKey = null,
    ) {
    }
}
