<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * A backed enum case used as a column `default:`, paired with the equivalent literal.
 *
 * `default: AccessRight::Write` and `default: 'write'` must produce an identical
 * ColumnDefinition — the enum form exists so the attribute can name the vocabulary that owns
 * the value instead of restating it, and so it stays writable below PHP 8.2, where
 * `AccessRight::Write->value` is not a valid constant expression.
 */
#[Table(name: 'attrecord_enum_defaults')]
final class EnumDefaultRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Enum, enumValues: ['read', 'write', 'admin'], default: AccessRight::Write)]
    public string $via_enum_case = 'write';

    #[Column(ColumnType::Enum, enumValues: ['read', 'write', 'admin'], default: 'write')]
    public string $via_literal = 'write';

    #[Column(ColumnType::TinyIntUnsigned, default: HttpStatusGroup::Success)]
    public int $int_backed = 2;
}
