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
    public function __construct(
        public readonly ColumnType $type,
        public readonly bool $nullable = false,
        public readonly bool $autoIncrement = false,
        public readonly ?bool $trimOnSave = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
    ) {
    }
}
