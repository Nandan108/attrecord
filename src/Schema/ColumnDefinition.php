<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

use Nandan108\Attrecord\Enum\ColumnType;

/**
 * Compiled, cached description of a single mapped column.
 *
 * @api
 */
final readonly class ColumnDefinition
{
    public bool $isInteger;
    public bool $isBool;
    public bool $isFloat;
    public bool $isNumeric;
    public bool $isBinary;
    public bool $isDateTime;
    public bool $isDate;
    public bool $isString;

    public function __construct(
        public string $name,
        public ColumnType $type,
        public bool $nullable,
        public bool $autoIncrement,
        public bool $trimOnSet,
        public ?int $length,
        public ?int $precision,
        public ?int $scale,
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
