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

    /** @var RecordSet<TagRecord>|null */
    #[Relation(RelationType::MorphMany, class: TagRecord::class, morphType: 'tagable_type', morphKey: 'tagable_id', morphValue: 'post')]
    public ?RecordSet $tags = null;

    /** @var RecordSet<CommentRecord>|null */
    #[Relation(RelationType::OneToMany, class: CommentRecord::class, foreignKey: 'post_id')]
    public ?RecordSet $comments = null;

    /** @var RecordSet<TagRecord>|null */
    #[Relation(RelationType::ManyToMany, class: TagRecord::class, pivotTable: 'attrecord_post_tag', pivotLocalKey: 'post_id', pivotForeignKey: 'tag_id')]
    public ?RecordSet $manyTags = null;
}
