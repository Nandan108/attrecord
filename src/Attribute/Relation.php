<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

use Nandan108\Attrecord\Enum\RelationType;

/**
 * Marks a public property as a relation to another Record class.
 *
 * The property must be typed `?RecordSet` and defaults to null until loaded
 * via Record::with() or RecordSet::with().
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Relation
{
    /**
     * @param class-string $class      Fully-qualified target Record subclass name
     * @param string       $foreignKey FK column on the related table (for OneToMany / OneToOneReversed)
     *                                 or FK column on this table (for ManyToOne / OneToOne)
     * @param string|null  $localKey   Local column used as the join key; defaults to this table's PK
     */
    public function __construct(
        public readonly RelationType $type,
        public readonly string $class,
        public readonly string $foreignKey,
        public readonly ?string $localKey = null,
    ) {
    }
}
