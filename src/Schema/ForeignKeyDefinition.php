<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Enum\ForeignKeyAction;

/**
 * Compiled description of one FOREIGN KEY constraint.
 *
 * Two sources, distinguished by which target field is set (exactly one is):
 *  - **`$targetClass`** — from an owning-side #[Relation] with `emitFk: true`; the target
 *    table + column are the target Record's table + PK.
 *  - **`$source`** — from a class-level #[ForeignKey]; the target is whatever that attribute
 *    resolves to (a Record class *or* a literal table name).
 *
 * Either way the target is resolved **lazily, at DDL-build time**, through
 * {@see targetTableName()} / {@see targetColumnName()} — so cyclic FK dependencies don't
 * re-enter the schema-build cache mid-build. Consumers read the target through those, never
 * by branching on the source.
 *
 * @api
 */
final class ForeignKeyDefinition
{
    /**
     * @param class-string|null $targetClass FQN of the target Record subclass (Relation-derived form)
     * @param ForeignKey|null   $source      the originating #[ForeignKey] attribute (Record-less / class form)
     */
    public function __construct(
        public readonly string $constraintName,
        public readonly string $localColumn,
        public readonly ForeignKeyAction $onDelete,
        public readonly ForeignKeyAction $onUpdate,
        public readonly ?string $targetClass = null,
        public readonly ?ForeignKey $source = null,
    ) {
    }

    /** Resolve the (prefixed) target table name. */
    public function targetTableName(): string
    {
        if (null !== $this->targetClass) {
            return TableSchema::fromClass($this->targetClass)->tableName;
        }

        return $this->source?->references()
            ?? throw new \LogicException('ForeignKeyDefinition has neither a targetClass nor a #[ForeignKey] source.');
    }

    /** Resolve the target column name. */
    public function targetColumnName(): string
    {
        if (null !== $this->targetClass) {
            return TableSchema::fromClass($this->targetClass)->pk;
        }

        return $this->source?->referencesColumn()
            ?? throw new \LogicException('ForeignKeyDefinition has neither a targetClass nor a #[ForeignKey] source.');
    }
}
