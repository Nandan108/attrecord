<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\ColumnSerializer;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use PHPUnit\Framework\TestCase;

final class ColumnSerializerTest extends TestCase
{
    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function col(ColumnType $type, ?bool $trimOnSave = null): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'col',
            propertyName: 'col',
            type: $type,
            nullable: false,
            autoIncrement: false,
            trimOnSave: $trimOnSave,
            length: null,
            precision: null,
            scale: null,
        );
    }

    // -----------------------------------------------------------------
    // trimOnSave — toParam()
    // -----------------------------------------------------------------

    public function testTrimOnSaveTrimsLeadingAndTrailingWhitespace(): void
    {
        $col = $this->col(ColumnType::VarChar, trimOnSave: true);

        $this->assertSame('hello', ColumnSerializer::toParam('  hello  ', $col));
        $this->assertSame('hello', ColumnSerializer::toParam("\thello\n", $col));
    }

    public function testTrimOnSaveNullDoesNotTrim(): void
    {
        $col = $this->col(ColumnType::VarChar, trimOnSave: null);

        $this->assertSame('  hello  ', ColumnSerializer::toParam('  hello  ', $col));
    }

    public function testTrimOnSaveNullValuePassedThrough(): void
    {
        $col = $this->col(ColumnType::VarChar, trimOnSave: true);

        $this->assertNull(ColumnSerializer::toParam(null, $col));
    }

    public function testTrimOnSaveEmptyStringAfterTrimRemainsEmpty(): void
    {
        $col = $this->col(ColumnType::VarChar, trimOnSave: true);

        $this->assertSame('', ColumnSerializer::toParam('   ', $col));
    }

    // -----------------------------------------------------------------
    // trimOnSave — dirty detection via toSnapshotString()
    // -----------------------------------------------------------------

    public function testWhitespaceOnlyDiffIsNotDirtyWhenTrimOnSave(): void
    {
        $col = $this->col(ColumnType::VarChar, trimOnSave: true);

        // Both '  Alice  ' and 'Alice' produce the same snapshot string
        $this->assertSame(
            ColumnSerializer::toSnapshotString('Alice', $col),
            ColumnSerializer::toSnapshotString('  Alice  ', $col),
        );
    }

    public function testWhitespaceOnlyDiffIsDirtyWhenNotTrimOnSave(): void
    {
        $col = $this->col(ColumnType::VarChar, trimOnSave: null);

        $this->assertNotSame(
            ColumnSerializer::toSnapshotString('Alice', $col),
            ColumnSerializer::toSnapshotString('  Alice  ', $col),
        );
    }

    // -----------------------------------------------------------------
    // trimOnSave — schema validation
    // -----------------------------------------------------------------

    public function testTrimOnSaveOnNonStringColumnThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('trimOnSave is only valid for string column types');

        // Force a fresh schema build with an invalid fixture
        TableSchema::clearCache(TrimOnSaveInvalidRecord::class);
        TableSchema::fromClass(TrimOnSaveInvalidRecord::class);
    }
}

// Inline fixture — trimOnSave on an integer column
#[\Nandan108\Attrecord\Attribute\Table(name: 'dummy')]
final class TrimOnSaveInvalidRecord extends \Nandan108\Attrecord\Record
{
    #[\Nandan108\Attrecord\Attribute\Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[\Nandan108\Attrecord\Attribute\Column(ColumnType::Int, trimOnSave: true)]
    public int $quantity = 0;
}
