<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;

/**
 * Declares a FOREIGN KEY, emitting the constraint alone (no relation property). The attribute
 * lives on the *referencing* Record (a normal attrecord Record whose DDL is generated); the
 * FK **target** is named by {@see $references}, which may be either:
 *
 *  - a **table name** — for a target attrecord doesn't model (hand-written raw-SQL DDL, or an
 *    externally owned table); the active table prefix is applied, and {@see $referencesColumn}
 *    names the target column (default `id`); or
 *  - a **Record class-string** — the target table name and its primary key are derived from
 *    that Record (rename-safe), without creating a relation to hydrate.
 *
 * A `$references` value that *is* a class but **not** a {@see Record} subclass is a mistake and
 * throws at resolution. A value that isn't a class is taken as a table name.
 *
 * Class-level and repeatable, mirroring the class-level form of {@see Index}: the local column
 * is a plain {@see Column} on this Record, and the FK is declared on the class.
 *
 *     #[Table(name: 'invflux_inventory_ledger')]
 *     #[ForeignKey(column: 'subject_id', references: Subject::class, onDelete: ForeignKeyAction::Restrict)]
 *     #[ForeignKey(column: 'from_slot_id', references: 'invflux_slotspace', onDelete: ForeignKeyAction::SetNull)]
 *     final class InventoryLedger extends Record { ... }
 *
 * Use {@see Relation} when you also want object hydration of the target; `#[ForeignKey]` is the
 * constraint-only declaration (and the only option when the target has no Record). The target
 * is resolved lazily, at DDL-build time, via {@see references()} / {@see referencesColumn()}.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class ForeignKey
{
    /**
     * Memoised classification of each `$references` value: `true` = Record class-string,
     * `false` = literal table name. Only the classification is cached — it is
     * prefix-independent, so it never goes stale. The (prefix-dependent) schema/table name
     * itself is read fresh from `TableSchema::fromClass()`, which owns its own prefix-aware
     * cache; caching the prefixed schema a second time here is what would go stale on a
     * prefix change.
     *
     * @var array<string, bool>
     */
    private static array $isRecordClass = [];

    /**
     * @param string           $column           local FK column (must be a declared #[Column])
     * @param string           $references       target table base name (un-prefixed) OR target Record class-string
     * @param string           $referencesColumn target column when `$references` is a table name (ignored for a Record class — its PK is used)
     * @param ForeignKeyAction $onDelete         ON DELETE action
     * @param ForeignKeyAction $onUpdate         ON UPDATE action
     */
    public function __construct(
        public readonly string $column,
        private readonly string $references,
        private readonly string $referencesColumn = 'id',
        public readonly ForeignKeyAction $onDelete = ForeignKeyAction::Restrict,
        public readonly ForeignKeyAction $onUpdate = ForeignKeyAction::Restrict,
    ) {
    }

    /**
     * Resolve the (prefixed) target table name — derived from the target Record when
     * `$references` is a Record class-string, otherwise the prefixed literal table name.
     */
    public function references(): string
    {
        return $this->getTargetSchema()?->tableName ?? Record::tablePrefix().$this->references;
    }

    /**
     * Resolve the target column — the target Record's primary key when `$references` is a
     * Record class-string, otherwise the given column name.
     */
    public function referencesColumn(): string
    {
        return $this->getTargetSchema()?->pk ?? $this->referencesColumn;
    }

    /**
     * Whether `$references` names a Record subclass (vs. a literal table name). Throws if it
     * names a class that is not a Record. Memoised, since the answer never varies.
     */
    private function getTargetSchema(): ?TableSchema
    {
        $isRecordClass = self::$isRecordClass[$this->references] ??= (function (): bool {
            if (!class_exists($this->references)) {
                return false; // a literal table name
            }
            if (!is_subclass_of($this->references, Record::class)) {
                throw new SchemaException(sprintf(
                    '#[ForeignKey] references "%s", which is a class but not a %s subclass. '
                    .'Pass a Record class-string (target table + PK are derived) or a table name.',
                    $this->references,
                    Record::class,
                ));
            }

            return true;
        })();

        if (!$isRecordClass) {
            return null;
        }

        // Read the schema fresh (fromClass caches it prefix-safely); only the classification
        // above is memoised here, so a prefix change can't leave a stale table name behind.
        // The classification guarantees this is a Record subclass.
        /** @psalm-var class-string $class */
        $class = $this->references;

        return TableSchema::fromClass($class);
    }
}
