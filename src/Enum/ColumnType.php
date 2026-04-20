<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/** @api */
enum ColumnType: string
{
    // --- Integers (PHP int) ---
    case TinyInt = 'tinyint';
    case SmallInt = 'smallint';
    case MediumInt = 'mediumint';
    case Int = 'int';
    case BigInt = 'bigint';
    case TinyIntUnsigned = 'tinyint unsigned';
    case SmallIntUnsigned = 'smallint unsigned';
    case MediumIntUnsigned = 'mediumint unsigned';
    case IntUnsigned = 'int unsigned';
    case BigIntUnsigned = 'bigint unsigned';
    case Year = 'year';
    case Bit = 'bit';

    // --- Boolean (PHP bool, stored as 0/1) ---
    case Bool = 'bool';

    // --- Floats (PHP float) ---
    case Float = 'float';
    case Double = 'double';
    case Decimal = 'decimal';

    // --- Strings (PHP string) ---
    case Char = 'char';
    case VarChar = 'varchar';
    case TinyText = 'tinytext';
    case Text = 'text';
    case MediumText = 'mediumtext';
    case LongText = 'longtext';
    case Json = 'json';
    case Enum = 'enum';
    case Set = 'set';

    // --- Binary (PHP string, raw bytes) ---
    case Binary = 'binary';
    case VarBinary = 'varbinary';

    // --- Date / time (PHP DateTimeImmutable) ---
    case Date = 'date';
    case DateTime = 'datetime';
    case Timestamp = 'timestamp';

    // --- Derived type flags ---

    public function isInteger(): bool
    {
        return match ($this) {
            self::TinyInt, self::SmallInt, self::MediumInt, self::Int, self::BigInt,
            self::TinyIntUnsigned, self::SmallIntUnsigned, self::MediumIntUnsigned,
            self::IntUnsigned, self::BigIntUnsigned,
            self::Year, self::Bit => true,
            default               => false,
        };
    }

    public function isBool(): bool
    {
        return self::Bool === $this;
    }

    public function isFloat(): bool
    {
        return match ($this) {
            self::Float, self::Double, self::Decimal => true,
            default                                  => false,
        };
    }

    public function isNumeric(): bool
    {
        return $this->isInteger() || $this->isFloat() || $this->isBool();
    }

    public function isBinary(): bool
    {
        return match ($this) {
            self::Binary, self::VarBinary => true,
            default                       => false,
        };
    }

    public function isDateTime(): bool
    {
        return match ($this) {
            self::DateTime, self::Timestamp => true,
            default                         => false,
        };
    }

    public function isDate(): bool
    {
        return self::Date === $this;
    }

    public function isString(): bool
    {
        return !$this->isInteger()
            && !$this->isBool()
            && !$this->isFloat()
            && !$this->isBinary()
            && !$this->isDateTime()
            && !$this->isDate();
    }
}
