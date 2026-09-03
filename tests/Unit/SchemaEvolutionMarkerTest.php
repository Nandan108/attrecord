<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Absent;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Mutable;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Attribute\Unmanaged;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\SchemaObjectKind;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Immutable;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declarations that describe a table by something other than its present shape: `renamedFrom`
 * on an index or unique key, `#[Absent]` for a retired object, `#[Unmanaged]` for one belonging to
 * another authority, and `#[Mutable]` for the columns an immutable row still lets move.
 *
 * All are **inert here** in the sense that matters: this suite pins that they are collected, that
 * the ways of writing them wrong are refused where they are written, and — the load-bearing one —
 * that none of them changes a byte of emitted DDL. Acting on the first three is
 * `attrecord-migrations`' job; `#[Mutable]` is read by the write guards, covered by the integration
 * cases that can actually attempt a write.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class SchemaEvolutionMarkerTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    #[Test]
    public function renamesAreCollectedFromBothDeclarationForms(): void
    {
        $schema = TableSchema::fromClass(EvolvedRecord::class);

        // Class-level (the composite index) and property-level (the unique key) alike.
        self::assertSame('idx_old_status_date', $schema->indexRenames['idx_status_date']->from);
        self::assertSame('1.4.0', $schema->indexRenames['idx_status_date']->since);
        self::assertSame('uk_old_ref', $schema->indexRenames['uk_external_ref']->from);
        self::assertNull($schema->indexRenames['uk_external_ref']->since, 'the version is optional');

        // An index with no rename declared contributes no entry at all.
        self::assertArrayNotHasKey('idx_plain', $schema->indexRenames);
    }

    #[Test]
    public function absencesAreCollectedPerKindAndAcceptOneNameOrMany(): void
    {
        $schema = TableSchema::fromClass(EvolvedRecord::class);

        $indexes = $schema->absent[SchemaObjectKind::Index->value];
        self::assertSame(['idx_legacy_sku', 'idx_legacy_isbn'], array_keys($indexes));
        self::assertSame('1.4.0', $indexes['idx_legacy_sku']->since);

        $columns = $schema->absent[SchemaObjectKind::Column->value];
        self::assertSame(['po_id'], array_keys($columns), 'a single name needs no array');
        self::assertSame('2.0.0', $columns['po_id']->since, 'a second declaration carries its own version');

        // Kinds nobody named are present and empty, so a reader never has to guard the lookup.
        self::assertSame([], $schema->absent[SchemaObjectKind::ForeignKey->value]);
    }

    #[Test]
    public function anAbsenceDescribesItselfWithAndWithoutAVersion(): void
    {
        $schema = TableSchema::fromClass(EvolvedRecord::class);

        self::assertSame(
            'declared absent since 1.4.0',
            $schema->absent[SchemaObjectKind::Index->value]['idx_legacy_sku']->describe(),
        );
        self::assertSame(
            'declared absent',
            $schema->absent[SchemaObjectKind::Check->value]['chk_legacy_range']->describe(),
        );
    }

    #[Test]
    public function neitherMarkerReachesTheEmittedDdl(): void
    {
        // The point of "inert in core": a CREATE TABLE describes what exists. History does not
        // belong in it, and an absence has nothing to say there at all.
        $ddl = (new MysqlDialect())->buildCreateTable(TableSchema::fromClass(EvolvedRecord::class));

        foreach (['idx_old_status_date', 'uk_old_ref', 'idx_legacy_sku', 'idx_legacy_isbn', 'po_id', 'chk_legacy_range'] as $ghost) {
            self::assertStringNotContainsString($ghost, $ddl, "\"{$ghost}\" is history, not shape");
        }
        self::assertStringContainsString('idx_status_date', $ddl, 'the current name is emitted');
    }

    #[Test]
    public function unmanagedObjectsAreCollectedPerKind(): void
    {
        $schema = TableSchema::fromClass(NotOursRecord::class);

        self::assertSame(['idx_dba_covering'], array_keys($schema->unmanaged[SchemaObjectKind::Index->value]));
        self::assertSame(['audit_hash', 'audit_at'], array_keys($schema->unmanaged[SchemaObjectKind::Column->value]));
        self::assertSame([], $schema->unmanaged[SchemaObjectKind::Check->value]);
    }

    #[Test]
    public function anObjectCannotBeBothAbsentAndUnmanaged(): void
    {
        // "must not exist" and "exists, but is not mine" cannot both hold, and the differ could
        // only reconcile them by picking one.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('named by more than one #[Absent]/#[Unmanaged] declaration');
        TableSchema::fromClass(BothMarkersRecord::class);
    }

    #[Test]
    public function mutableColumnsAreCollectedOnAnImmutableRecord(): void
    {
        $schema = TableSchema::fromClass(FlaggableRecord::class);

        self::assertSame(['invalid_at'], array_keys($schema->mutableColumns));
        self::assertArrayNotHasKey('name', $schema->mutableColumns, 'the facts themselves do not move');
    }

    #[Test]
    public function aRecordThatIsNotImmutableHasNoMutableColumns(): void
    {
        // Not "none declared" but "the question does not arise": every column of an ordinary
        // Record is writable, so the map is empty rather than absent.
        self::assertSame([], TableSchema::fromClass(EvolvedRecord::class)->mutableColumns);
    }

    #[Test]
    public function mutableOnARecordThatPromisesNothingIsRefused(): void
    {
        // The marker would exempt the column from nothing, while telling a reader it moves — worse
        // than not writing it.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('the Record is not Immutable');
        TableSchema::fromClass(PointlesslyMutableRecord::class);
    }

    #[Test]
    public function mutableOnThePrimaryKeyIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('#[Mutable] on the primary key');
        TableSchema::fromClass(MutablePkRecord::class);
    }

    #[Test]
    public function declaringSomethingBothPresentAndAbsentIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('declared both present and #[Absent]');
        TableSchema::fromClass(ContradictoryRecord::class);
    }

    #[Test]
    public function anAbsenceIsRefusedIfDeclaredTwice(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('named by more than one #[Absent]/#[Unmanaged] declaration');
        TableSchema::fromClass(DuplicateAbsenceRecord::class);
    }

    #[Test]
    public function anAbsenceNamingNothingIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('names nothing');
        TableSchema::fromClass(EmptyAbsenceRecord::class);
    }

    #[Test]
    public function aVersionWithoutARenameIsRefused(): void
    {
        // `renamedSince` dates a rename; on its own it states nothing, and silently ignoring it
        // would hide a half-written declaration.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('without `renamedFrom`');
        TableSchema::fromClass(DanglingSinceRecord::class);
    }

    #[Test]
    public function aRenamePointingAtItselfIsRefused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('pointing at itself');
        TableSchema::fromClass(SelfRenameRecord::class);
    }

    #[Test]
    public function aCompositeMayRepeatItsRenameButTheRepeatsMustAgree(): void
    {
        // Property-level composites name the same key on every member, so the rename arrives once
        // per column; agreeing repeats fold, disagreeing ones are a typo worth catching.
        $schema = TableSchema::fromClass(AgreeingCompositeRecord::class);
        self::assertSame('uk_old', $schema->indexRenames['uk_pair']->from);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('two different renames');
        TableSchema::fromClass(DisagreeingCompositeRecord::class);
    }
}

#[Table(name: 'evolved')]
#[Index('idx_status_date', columns: ['status', 'created_at'], renamedFrom: 'idx_old_status_date', renamedSince: '1.4.0')]
#[Absent(index: ['idx_legacy_sku', 'idx_legacy_isbn'], since: '1.4.0')]
#[Absent(column: 'po_id', since: '2.0.0')]
#[Absent(check: 'chk_legacy_range')]
final class EvolvedRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[Index('idx_plain')]
    public string $status = '';

    #[Column(ColumnType::DateTime)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uk_external_ref', renamedFrom: 'uk_old_ref')]
    public string $external_ref = '';
}

#[Table(name: 'not_ours')]
#[Unmanaged(index: 'idx_dba_covering', column: ['audit_hash', 'audit_at'])]
final class NotOursRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

#[Table(name: 'both_markers')]
#[Absent(index: 'idx_x')]
#[Unmanaged(index: 'idx_x')]
final class BothMarkersRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

#[Table(name: 'contradictory')]
#[Absent(index: 'idx_status')]
final class ContradictoryRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[Index('idx_status')]
    public string $status = '';
}

#[Table(name: 'dup_absence')]
#[Absent(index: 'idx_gone', since: '1.0.0')]
#[Absent(index: 'idx_gone', since: '1.1.0')]
final class DuplicateAbsenceRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

#[Table(name: 'empty_absence')]
#[Absent(since: '1.0.0')]
final class EmptyAbsenceRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

#[Table(name: 'dangling_since')]
#[Index('idx_thing', columns: ['thing'], renamedSince: '1.4.0')]
final class DanglingSinceRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $thing = '';
}

#[Table(name: 'self_rename')]
#[Index('idx_thing', columns: ['thing'], renamedFrom: 'idx_thing')]
final class SelfRenameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $thing = '';
}

#[Table(name: 'agreeing_composite')]
final class AgreeingCompositeRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uk_pair', renamedFrom: 'uk_old')]
    public string $left = '';

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uk_pair', renamedFrom: 'uk_old')]
    public string $right = '';
}

#[Table(name: 'disagreeing_composite')]
final class DisagreeingCompositeRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uk_pair', renamedFrom: 'uk_old')]
    public string $left = '';

    #[Column(ColumnType::VarChar, length: 32)]
    #[UniqueKey('uk_pair', renamedFrom: 'uk_other')]
    public string $right = '';
}

#[Table(name: 'flaggable')]
final class FlaggableRecord extends Record implements Immutable
{
    #[Column(ColumnType::BigIntUnsigned)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $name = '';

    #[Column(ColumnType::DateTime, nullable: true)]
    #[Mutable]
    public ?\DateTimeImmutable $invalid_at = null;
}

#[Table(name: 'pointlessly_mutable')]
final class PointlesslyMutableRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[Mutable]
    public string $whatever = '';
}

#[Table(name: 'mutable_pk')]
final class MutablePkRecord extends Record implements Immutable
{
    #[Column(ColumnType::BigIntUnsigned)]
    #[Mutable]
    public ?int $id = null;
}
