<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Fixture exercising auto-timestamps (#[CreatedAt]/#[UpdatedAt]) and the lifecycle hooks
 * (afterSave/beforeDelete/afterDelete/afterLoad), which record their invocations in `$hookLog`.
 */
#[Table(name: 'attrecord_timestamped')]
final class TimestampedRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[Column(ColumnType::DateTime, nullable: true)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, nullable: true)]
    #[UpdatedAt]
    public ?\DateTimeImmutable $updated_at = null;

    // ---- lifecycle-hook instrumentation (not columns) ----

    /** @var list<string> */
    public array $hookLog = [];

    public ?bool $lastAfterSaveWasInsert = null;

    #[\Override]
    public function afterSave(bool $wasInsert): void
    {
        $this->hookLog[] = 'afterSave';
        $this->lastAfterSaveWasInsert = $wasInsert;
    }

    #[\Override]
    public function beforeDelete(): void
    {
        $this->hookLog[] = 'beforeDelete';
    }

    #[\Override]
    public function afterDelete(): void
    {
        $this->hookLog[] = 'afterDelete';
    }

    #[\Override]
    public function afterLoad(): void
    {
        $this->hookLog[] = 'afterLoad';
    }
}
