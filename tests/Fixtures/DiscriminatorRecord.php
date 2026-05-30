<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Fixture for a caster that reads a sibling discriminator column ({@see KindPayloadCaster}).
 */
#[Table(name: 'disc_records')]
final class DiscriminatorRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 20)]
    public string $kind = '';

    #[Column(ColumnType::Json, nullable: true)]
    #[KindPayloadCaster]
    public ?array $payload = null;
}
