<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;

#[Table(name: 'attrecord_posts')]
final class PostRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $user_id = 0;

    #[Column(ColumnType::VarChar, length: 200)]
    public string $title = '';

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $body = null;

    #[Relation(RelationType::ManyToOne, class: UserRecord::class, foreignKey: 'user_id')]
    public ?UserRecord $user = null;
}
