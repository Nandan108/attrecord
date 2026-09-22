<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Exception\SchemaException;

/**
 * Compiled description of one FOREIGN KEY constraint, over one column or several.
 *
 * Two sources, distinguished by which target field is set (exactly one is):
 *  - **`$targetClass`** — from an owning-side #[Relation] with `emitFk: true`; the target
 *    table + columns are the target Record's table + primary key.
 *  - **`$source`** — from a class-level #[ForeignKey]; the target is whatever that attribute
 *    resolves to (a Record class *or* a literal table name).
 *
 * Either way the target is resolved **lazily, at DDL-build time**, through
 * {@see targetTableName()} / {@see targetColumnNames()} — so cyclic FK dependencies don't
 * re-enter the schema-build cache mid-build. Consumers read the target through those, never
 * by branching on the source.
 *
 * **Column order is the constraint**: `$localColumns[$i]` references `targetColumnNames()[$i]`,
 * so the two lists are read together and never sorted. A multi-column key with its pairs shuffled
 * is a different constraint that reads like the same one.
 *
 * @api
 */
final class ForeignKeyDefinition
{
    /**
     * @param list<string>      $localColumns local FK columns, in constraint order
     * @param class-string|null $targetClass  FQN of the target Record subclass (Relation-derived form)
     * @param ForeignKey|null   $source       the originating #[ForeignKey] attribute (Record-less / class form)
     */
    public function __construct(
        public readonly string $constraintName,
        public readonly array $localColumns,
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

    /**
     * Resolve the target columns, in constraint order and paired with {@see $localColumns}.
     *
     * For a Record target this is the target's whole primary key — every member, because a foreign
     * key that names a *prefix* of a key is a different constraint that no engine agrees about:
     * MySQL 8.0 and MariaDB accept it, MySQL 8.4+ (err 6125) and PostgreSQL reject it, and SQLite
     * accepts the DDL and then refuses every child insert.
     *
     * @return list<string>
     *
     * @throws SchemaException when the two sides do not have the same number of columns
     */
    public function targetColumnNames(): array
    {
        $target = null !== $this->targetClass
            ? TableSchema::fromClass($this->targetClass)->pkColumns()
            : ($this->source?->referencesColumns()
                ?? throw new \LogicException('ForeignKeyDefinition has neither a targetClass nor a #[ForeignKey] source.'));

        if (\count($target) !== \count($this->localColumns)) {
            throw new SchemaException(sprintf(
                'Constraint "%s" references %s (%s) from (%s): a foreign key pairs each local column '
                .'with one referenced column, so the two sides must have the same number of columns. '
                .'Naming fewer references a prefix of the key, which MySQL 8.0 and MariaDB accept as a '
                .'different constraint, MySQL 8.4+ and PostgreSQL reject, and SQLite accepts before '
                .'refusing every insert.',
                $this->constraintName,
                $this->targetTableName(),
                implode(', ', $target),
                implode(', ', $this->localColumns),
            ));
        }

        return $target;
    }
}
