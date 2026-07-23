<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** Auto-increment PK + a unique business key (`code`) — for upsert-by-unique-key tests. */
#[Table(name: 'attrecord_upsert')]
#[UniqueKey('uniq_code', columns: ['code'])]
final class UpsertByUniqueKeyRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    public ?string $note = null;

    /** Test convenience builder. */
    public function withCode(string $code, string $name): static
    {
        $this->code = $code;
        $this->name = $name;

        return $this;
    }
}
