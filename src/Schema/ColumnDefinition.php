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
     * @param list<string> $uniqueKeyNames Names of non-PK unique keys this column belongs to
     */
    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly bool $nullable,
        public readonly bool $autoIncrement,
        public readonly ?bool $trimOnSave,
        public readonly ?int $length,
        public readonly ?int $precision,
        public readonly ?int $scale,
        public readonly array $uniqueKeyNames = [],
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
