<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Record;

/**
 * Exercises the generated-column DDL surface: STORED expression appears in
 * CREATE TABLE; the column participates in a compound UNIQUE key; writes
 * (INSERT / UPDATE) skip the generated column transparently.
 */
#[Table(name: 'attrecord_gen_col')]
#[UniqueKey('uq_scope_value', columns: ['scope_key', 'value'])]
final class DdlGeneratedColumnRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $scope_id = null;

    // COALESCE is portable across MySQL/MariaDB and PostgreSQL (unlike MySQL's IFNULL),
    // so this fixture's generated-column DDL can be created on either backend.
    #[Column(
        ColumnType::IntUnsigned,
        generatedAs: 'COALESCE(scope_id, 0)',
        generatedMode: GeneratedColumnMode::Stored,
    )]
    public int $scope_key = 0;

    #[Column(ColumnType::VarChar, length: 64)]
    public string $value = '';
}
