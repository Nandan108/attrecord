<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Junction table for the Post ⇄ Tag ManyToMany. Modelled as a Record only so the test harness
 * creates its table; the ManyToMany loader queries the table directly, not this class.
 */
#[Table(name: 'attrecord_post_tag')]
final class PostTagPivot extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $post_id = 0;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $tag_id = 0;
}
