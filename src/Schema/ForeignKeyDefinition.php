<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Enum\ForeignKeyAction;

/**
 * Compiled description of one FOREIGN KEY constraint derived from an owning-side
 * #[Relation] attribute (ManyToOne or OneToOne) with `emitFk: true`.
 *
 * The target table name and target column are resolved lazily from `$targetClass`
 * (via `TableSchema::fromClass()`) so cyclic FK dependencies don't blow the
 * schema-build cache.
 *
 * @api
 */
final class ForeignKeyDefinition
{
    /**
     * @param class-string $targetClass FQN of the target Record subclass
     */
    public function __construct(
        public readonly string $constraintName,
        public readonly string $localColumn,
        public readonly string $targetClass,
        public readonly ForeignKeyAction $onDelete,
        public readonly ForeignKeyAction $onUpdate,
    ) {
    }
}
