<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;

/**
 * Exercises the full DDL-emission surface: defaults, comments, enum, decimal,
 * datetime with on-update, composite class-level UniqueKey + Index, FK with
 * Cascade-on-delete, table comment.
 */
#[Table(name: 'attrecord_ddl_orders', comment: 'Test orders table')]
#[UniqueKey('uk_customer_date', columns: ['customer_id', 'created_at'])]
#[Index('idx_status_date', columns: ['status', 'created_at'])]
final class DdlOrderRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $customer_id = 0;

    #[Column(ColumnType::VarChar, length: 20, default: 'pending', comment: 'Workflow state')]
    public string $status = 'pending';

    #[Column(
        ColumnType::Enum,
        enumValues: ['cash', 'card', 'wire'],
        default: 'cash',
    )]
    public string $payment_method = 'cash';

    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: 0)]
    public float $total = 0.0;

    #[Column(ColumnType::Bool, default: false)]
    public bool $is_paid = false;

    #[Column(ColumnType::DateTime, defaultExpr: 'CURRENT_TIMESTAMP')]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(
        ColumnType::DateTime,
        defaultExpr: 'CURRENT_TIMESTAMP',
        onUpdate: 'CURRENT_TIMESTAMP',
    )]
    public ?\DateTimeImmutable $updated_at = null;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    #[UniqueKey('uk_external_ref')]
    public ?string $external_ref = null;

    #[Relation(
        RelationType::ManyToOne,
        class: UserRecord::class,
        foreignKey: 'customer_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?UserRecord $customer = null;
}
