<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;

/**
 * Marks a public property as a mapped database column.
 *
 * Do NOT declare column properties as `readonly` — the active-record lifecycle (hydration
 * on load, PK assignment after INSERT, reload) requires re-assignment.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column
{
    /**
     * @param ColumnType                             $type          SQL column type
     * @param string|null                            $name          column name override; defaults to the PHP property name when omitted (no auto-conversion)
     * @param bool                                   $nullable      NULL allowed
     * @param bool                                   $autoIncrement auto-increment / IDENTITY
     * @param bool|null                              $trimOnSave    trim string values on save (string types only)
     * @param int|null                               $length        column length (VARCHAR/CHAR/BINARY/VARBINARY/BIT)
     * @param int|null                               $precision     numeric/temporal precision: for ColumnType::Decimal — total significant digits (required, paired with $scale); for ColumnType::DateTime / ColumnType::Timestamp — fractional-seconds precision (0-6). Forbidden on other types.
     * @param int|null                               $scale         decimal scale (digits after the decimal point); only valid with ColumnType::Decimal, forbidden elsewhere
     * @param int|float|string|bool|\BackedEnum|null $default       Literal default value. `null` means "no default specified" (use `defaultExpr: 'NULL'` for an explicit DEFAULT NULL). Mutually exclusive with $defaultExpr. A **backed enum case** is accepted and unwrapped to its backing value when the ColumnDefinition is built — so `default: Status::Active` is equivalent to `default: 'active'`, but keeps the attribute tied to the vocabulary that owns the value. It is also the only form usable below PHP 8.2: `Status::Active->value` is a property fetch, which is not a valid constant expression before 8.2, so writing it in an attribute makes the whole class unparseable on 8.1.
     * @param string|null                            $defaultExpr   Raw SQL default expression (e.g. 'CURRENT_TIMESTAMP'). Mutually exclusive with $default.
     * @param string|null                            $onUpdate      Raw SQL ON UPDATE expression (e.g. 'CURRENT_TIMESTAMP').
     * @param string|null                            $comment       column comment
     * @param list<string>|null                      $enumValues    enum/Set allowed values; required for ColumnType::Enum and ColumnType::Set
     * @param string|null                            $generatedAs   Raw SQL expression for a generated column (e.g. 'IFNULL(scope_actor_id, 0)'). Mutually exclusive with $default, $defaultExpr, $onUpdate, $autoIncrement. The corresponding PHP property is read-only at the application layer — the database computes the value.
     * @param GeneratedColumnMode|null               $generatedMode Storage mode for the generated column. Defaults to `Stored` when $generatedAs is set and $generatedMode is omitted.
     * @param string|null                            $renamedFrom   Previous column name, for schema-evolution tooling (the `attrecord-migrations` companion): a declared rename is emitted as data-preserving `RENAME COLUMN` instead of a destructive drop+add. **Inert in core** — stored on the ColumnDefinition, never read by CRUD or the DDL producer. Cheap documentation of the column's history, and the only thing standing between an upgrade and a drop+add that would take the data with it — which is why deleting one is a decision, not tidying. See https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md §4.3.
     * @param string|null                            $renamedSince  Release the rename shipped in, for example '1.4.0'. **Opaque** — stored and never compared; see {@see Absent::$since}. Its purpose is to make "which of these markers still has a live install behind it?" a question a tool can answer, rather than one answered by memory.
     */
    public function __construct(
        public readonly ColumnType $type,
        public readonly ?string $name = null,
        public readonly bool $nullable = false,
        public readonly bool $autoIncrement = false,
        public readonly ?bool $trimOnSave = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly int | float | string | bool | \BackedEnum | null $default = null,
        public readonly ?string $defaultExpr = null,
        public readonly ?string $onUpdate = null,
        public readonly ?string $comment = null,
        public readonly ?array $enumValues = null,
        public readonly ?string $generatedAs = null,
        public readonly ?GeneratedColumnMode $generatedMode = null,
        public readonly ?string $renamedFrom = null,
        public readonly ?string $renamedSince = null,
    ) {
    }
}
