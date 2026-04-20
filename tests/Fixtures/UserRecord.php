<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;

#[Table(name: 'attrecord_users')]
final class UserRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::VarChar, length: 200, nullable: true)]
    public ?string $email = null;

    #[Column(ColumnType::Bool, nullable: true)]
    public ?bool $active = null;

    /** @var RecordSet<PostRecord>|null */
    #[Relation(RelationType::OneToMany, class: PostRecord::class, foreignKey: 'user_id')]
    public ?RecordSet $posts = null;
}
