<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\BitmaskCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Portable flag-set fixture: a set of {@see StockConcern} members stored as an integer bitmask.
 * Runs on every backend (the column is a plain integer). `concerns` is non-nullable (empty set = 0);
 * `optional_concerns` is nullable to exercise the NULL-vs-empty-set distinction.
 */
#[Table(name: 'bitmask_records')]
final class BitmaskRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** @var list<StockConcern> */
    #[Column(ColumnType::BigIntUnsigned, default: 0)]
    #[BitmaskCaster(StockConcern::class)]
    public array $concerns = [];

    /** @var list<StockConcern>|null */
    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    #[BitmaskCaster(StockConcern::class)]
    public ?array $optional_concerns = null;
}
