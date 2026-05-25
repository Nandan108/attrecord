<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

use Nandan108\Attrecord\Enum\ColumnType;

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
     * @param ColumnType                 $type          SQL column type
     * @param string|null                $name          column name override; defaults to the PHP property name when omitted (no auto-conversion)
     * @param bool                       $nullable      NULL allowed
     * @param bool                       $autoIncrement auto-increment / IDENTITY
     * @param bool|null                  $trimOnSave    trim string values on save (string types only)
     * @param int|null                   $length        column length (VARCHAR/CHAR/BINARY/VARBINARY/BIT)
     * @param int|null                   $precision     decimal precision
     * @param int|null                   $scale         decimal scale
     * @param int|float|string|bool|null $default       Literal default value. `null` means "no default specified" (use `defaultExpr: 'NULL'` for an explicit DEFAULT NULL). Mutually exclusive with $defaultExpr.
     * @param string|null                $defaultExpr   Raw SQL default expression (e.g. 'CURRENT_TIMESTAMP'). Mutually exclusive with $default.
     * @param string|null                $onUpdate      Raw SQL ON UPDATE expression (e.g. 'CURRENT_TIMESTAMP').
     * @param string|null                $comment       column comment
     * @param list<string>|null          $enumValues    enum/Set allowed values; required for ColumnType::Enum and ColumnType::Set
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
        public readonly int | float | string | bool | null $default = null,
        public readonly ?string $defaultExpr = null,
        public readonly ?string $onUpdate = null,
        public readonly ?string $comment = null,
        public readonly ?array $enumValues = null,
    ) {
    }
}
