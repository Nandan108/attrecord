<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Enum\ColumnType;

/**
 * Compiled, cached description of a single mapped column.
 *
 * @api
 */
final class ColumnDefinition
{
    public readonly bool $isInteger;
    public readonly bool $isBool;
    public readonly bool $isFloat;
    public readonly bool $isNumeric;
    public readonly bool $isBinary;
    public readonly bool $isDateTime;
    public readonly bool $isDate;
    public readonly bool $isString;

    /**
     * @param string                     $name           SQL column name (post-override; equals `$propertyName` when no `name:` override was specified)
     * @param string                     $propertyName   PHP property name (always equals the property declaration, regardless of column-name override)
     * @param ColumnType                 $type           SQL column type
     * @param bool                       $nullable       NULL allowed
     * @param bool                       $autoIncrement  auto-increment / IDENTITY
     * @param bool|null                  $trimOnSave     trim string values on save
     * @param int|null                   $length         length (VARCHAR/CHAR/BINARY/VARBINARY/BIT)
     * @param int|null                   $precision      numeric (Decimal: total digits) or temporal (DateTime/Timestamp: fractional-seconds 0-6) precision
     * @param int|null                   $scale          decimal scale (digits after the decimal point); forbidden on non-Decimal types
     * @param list<string>               $uniqueKeyNames names of non-PK unique keys this column belongs to via property-level #[UniqueKey]
     * @param list<string>               $indexNames     names of non-unique indexes this column belongs to via property-level #[Index]
     * @param int|float|string|bool|null $default        Literal default value. `null` means "no default specified".
     * @param string|null                $defaultExpr    Raw SQL default expression (e.g. 'CURRENT_TIMESTAMP').
     * @param string|null                $onUpdate       raw SQL ON UPDATE expression
     * @param string|null                $comment        column comment
     * @param list<string>|null          $enumValues     allowed values for ColumnType::Enum / Set
     */
    public function __construct(
        public readonly string $name,
        public readonly string $propertyName,
        public readonly ColumnType $type,
        public readonly bool $nullable,
        public readonly bool $autoIncrement,
        public readonly ?bool $trimOnSave,
        public readonly ?int $length,
        public readonly ?int $precision,
        public readonly ?int $scale,
        public readonly array $uniqueKeyNames = [],
        public readonly array $indexNames = [],
        public readonly int | float | string | bool | null $default = null,
        public readonly ?string $defaultExpr = null,
        public readonly ?string $onUpdate = null,
        public readonly ?string $comment = null,
        public readonly ?array $enumValues = null,
    ) {
        $this->isInteger = $type->isInteger();
        $this->isBool = $type->isBool();
        $this->isFloat = $type->isFloat();
        $this->isNumeric = $type->isNumeric();
        $this->isBinary = $type->isBinary();
        $this->isDateTime = $type->isDateTime();
        $this->isDate = $type->isDate();
        $this->isString = $type->isString();
    }
}
