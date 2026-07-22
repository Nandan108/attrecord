<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UpdatedAt;
use Nandan108\Attrecord\Attribute\Version;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * Schema-build validation for the ManyToMany / HasManyThrough relation params and the
 * #[CreatedAt] / #[UpdatedAt] auto-timestamp attributes.
 */
final class RelationAndTimestampValidationTest extends TestCase
{
    public function testManyToManyRequiresPivotParams(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_mm')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            /** @var RecordSet<UserRecord>|null */
            #[Relation(RelationType::ManyToMany, class: UserRecord::class)] // missing pivot* params
            public ?RecordSet $rel = null;
        })::schema();
    }

    public function testHasManyThroughRequiresThroughAndSecondKey(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_hmt')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            /** @var RecordSet<UserRecord>|null */
            #[Relation(RelationType::HasManyThrough, class: UserRecord::class, foreignKey: 'x_id')] // missing through/secondKey
            public ?RecordSet $rel = null;
        })::schema();
    }

    public function testCreatedAtRequiresTemporalColumn(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_ts_type')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::VarChar, length: 20)]
            #[CreatedAt]
            public string $created = '';
        })::schema();
    }

    public function testAtMostOneCreatedAtColumn(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_dup_created')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::DateTime, nullable: true)]
            #[CreatedAt]
            public ?\DateTimeImmutable $c1 = null;

            #[Column(ColumnType::DateTime, nullable: true)]
            #[CreatedAt]
            public ?\DateTimeImmutable $c2 = null;
        })::schema();
    }

    public function testAtMostOneUpdatedAtColumn(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_dup_updated')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::DateTime, nullable: true)]
            #[UpdatedAt]
            public ?\DateTimeImmutable $u1 = null;

            #[Column(ColumnType::DateTime, nullable: true)]
            #[UpdatedAt]
            public ?\DateTimeImmutable $u2 = null;
        })::schema();
    }

    public function testVersionRequiresIntegerColumn(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_version_type')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::VarChar, length: 20)]
            #[Version]
            public string $version = '';
        })::schema();
    }

    public function testVersionCannotBeAGeneratedColumn(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_version_generated')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::IntUnsigned, generatedAs: '1')]
            #[Version]
            public int $version = 0;
        })::schema();
    }

    public function testAtMostOneVersionColumn(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'bad_dup_version')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::IntUnsigned)]
            #[Version]
            public ?int $v1 = null;

            #[Column(ColumnType::IntUnsigned)]
            #[Version]
            public ?int $v2 = null;
        })::schema();
    }
}
