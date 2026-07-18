<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * Column- and relation-attribute misconfigurations that {@see \Nandan108\Attrecord\Schema\TableSchema}
 * rejects at build time with a {@see SchemaException}.
 */
final class SchemaValidationTest extends TestCase
{
    public function testDuplicateColumnNameIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_dup_col')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::VarChar, length: 1, name: 'x')]
            public string $a = '';

            #[Column(ColumnType::VarChar, length: 1, name: 'x')]
            public string $b = '';
        })::schema();
    }

    public function testPropertyLevelUniqueKeyWithColumnsIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_uk_cols')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::VarChar, length: 10)]
            #[UniqueKey('k', columns: ['a'])]
            public string $a = '';
        })::schema();
    }

    public function testPropertyLevelIndexWithColumnsIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_ix_cols')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::VarChar, length: 10)]
            #[Index('i', columns: ['a'])]
            public string $a = '';
        })::schema();
    }

    public function testTrimOnSaveOnNonStringColumnIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_trim')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::IntUnsigned, trimOnSave: true)]
            public int $n = 0;
        })::schema();
    }

    public function testEnumColumnWithoutValuesIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_enum')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Column(ColumnType::Enum)] // no enumValues and no #[EnumCaster]
            public string $status = '';
        })::schema();
    }

    public function testRelationRequiresClass(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_rel_class')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            /** @var RecordSet<UserRecord>|null */
            #[Relation(RelationType::OneToMany, foreignKey: 'x_id')] // missing class
            public ?RecordSet $rel = null;
        })::schema();
    }

    public function testStandardRelationRequiresForeignKey(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_rel_fk')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            /** @var RecordSet<UserRecord>|null */
            #[Relation(RelationType::OneToMany, class: UserRecord::class)] // missing foreignKey
            public ?RecordSet $rel = null;
        })::schema();
    }

    public function testMorphParentRequiresTypeAndKey(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_morph_tk')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            /** @var RecordSet<UserRecord>|null */
            #[Relation(RelationType::MorphMany, class: UserRecord::class)] // missing morphType/morphKey
            public ?RecordSet $rel = null;
        })::schema();
    }

    public function testMorphParentRequiresValue(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_morph_val')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            /** @var RecordSet<UserRecord>|null */
            #[Relation(RelationType::MorphMany, class: UserRecord::class, morphType: 't', morphKey: 'k')] // missing morphValue
            public ?RecordSet $rel = null;
        })::schema();
    }

    public function testMorphChildRequiresMap(): void
    {
        $this->expectException(SchemaException::class);
        (new #[Table(name: 'v_morph_map')] class extends Record {
            #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
            public ?int $id = null;

            #[Relation(RelationType::MorphTo, morphType: 't', morphKey: 'k')] // missing morphMap
            public ?UserRecord $rel = null;
        })::schema();
    }
}
