<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\SetCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * MySQL-only flag-set fixture: a set of {@see AccessRight} members stored in a native `SET(...)`
 * column. The `SET(...)` member list is derived from the enum's cases (no inline `enumValues:`).
 */
#[Table(name: 'set_flags_records')]
final class SetFlagsRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** @var list<AccessRight> */
    #[Column(ColumnType::Set)]
    #[SetCaster(AccessRight::class)]
    public array $rights = [];
}
