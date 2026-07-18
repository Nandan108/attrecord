<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** Far side of the User → Post → Comment HasManyThrough chain. */
#[Table(name: 'attrecord_comments')]
final class CommentRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $post_id = 0;

    #[Column(ColumnType::VarChar, length: 200)]
    public string $body = '';
}
