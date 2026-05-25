<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Exercises the property-name / column-name split.
 *
 * Properties are camelCase (PHP convention); columns are snake_case (SQL convention).
 * The #[Table(primaryKey: ...)] argument and `name:` overrides on each #[Column]
 * are all column names.
 */
#[Table(name: 'attrecord_override', primaryKey: 'order_id')]
final class ColumnNameOverrideRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, name: 'order_id', autoIncrement: true)]
    public ?int $orderId = null;

    #[Column(ColumnType::BigIntUnsigned, name: 'customer_id')]
    public int $customerId = 0;

    #[Column(ColumnType::VarChar, length: 64, name: 'external_ref', nullable: true)]
    public ?string $externalRef = null;

    #[Column(ColumnType::DateTime, name: 'created_at', defaultExpr: 'CURRENT_TIMESTAMP')]
    public ?\DateTimeImmutable $createdAt = null;
}
