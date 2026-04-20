<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;

#[Table(name: 'attrecord_tags')]
final class TagRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 50)]
    public string $tagable_type = '';

    #[Column(ColumnType::BigIntUnsigned)]
    public int $tagable_id = 0;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Relation(
        RelationType::MorphTo,
        morphType: 'tagable_type',
        morphKey: 'tagable_id',
        morphMap: ['user' => UserRecord::class, 'post' => PostRecord::class],
    )]
    public UserRecord | PostRecord | null $tagable = null;
}
