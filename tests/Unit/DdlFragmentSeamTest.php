<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\Attrecord\Tests\Fixtures\DdlForeignKeyRecord;
use Nandan108\Attrecord\Tests\Fixtures\DdlOrderRecord;
use PHPUnit\Framework\TestCase;

/** Same shape as SeamRenameRecord minus renamedFrom — DDL must be byte-identical (marker is inert). */
#[Table(name: 'seam_rename_records')]
final class SeamPlainRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    public string $sku = '';
}

/** Carries the #[Column(renamedFrom:)] marker for the inertness + storage assertions. */
#[Table(name: 'seam_rename_records')]
final class SeamRenameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64, renamedFrom: 'sku_code')]
    public string $sku = '';
}

/**
 * The §8.1 core seams for the attrecord-migrations companion (docs/arch-migrations.md):
 * the public DDL fragment builders must render exactly what buildCreateTable() embeds
 * (one rendering authority for CREATE and ALTER), and #[Column(renamedFrom:)] must be
 * stored on the ColumnDefinition while remaining inert everywhere in core.
 */
final class DdlFragmentSeamTest extends TestCase
{
    /** @return list<array{SqlDialect}> */
    public static function dialects(): array
    {
        return [[new MysqlDialect()], [new PgsqlDialect()], [new SqliteDialect()]];
    }

    /** @dataProvider dialects */
    public function testColumnLineFragmentMatchesCreateTableRendering(SqlDialect $dialect): void
    {
        $schema = TableSchema::fromClass(DdlOrderRecord::class);
        $create = $dialect->buildCreateTable($schema);

        foreach ($schema->columns as $col) {
            if ($col->name === $schema->pk) {
                continue; // SQLite renders an inline-AI PK differently inside CREATE; the fragment form is non-PK by contract.
            }
            self::assertStringContainsString(
                $dialect->buildColumnLine($col),
                $create,
                $col->name.' fragment must appear verbatim in CREATE TABLE ('.$dialect::class.')',
            );
        }
    }

    /** @dataProvider dialects */
    public function testForeignKeyLineFragmentMatchesCreateTableRendering(SqlDialect $dialect): void
    {
        $schema = TableSchema::fromClass(DdlForeignKeyRecord::class);
        $create = $dialect->buildCreateTable($schema);

        self::assertNotSame([], $schema->foreignKeys, 'fixture must declare at least one FK');
        foreach ($schema->foreignKeys as $fk) {
            self::assertStringContainsString(
                $dialect->buildForeignKeyLine($fk),
                $create,
                'FK fragment must appear verbatim in CREATE TABLE ('.$dialect::class.')',
            );
        }
    }

    /**
     * The omit-FK seam: circular references have no creation order that works with every FK
     * inline, so one constraint is left out of CREATE and added by ALTER once both tables exist.
     *
     * @dataProvider dialects
     */
    public function testOmittedForeignKeyIsLeftOutButTheOthersRemain(SqlDialect $dialect): void
    {
        $schema = TableSchema::fromClass(DdlForeignKeyRecord::class);
        self::assertGreaterThan(1, \count($schema->foreignKeys), 'fixture must declare several FKs');

        $omitted = $schema->foreignKeys[0];
        $create = $dialect->buildCreateTable($schema, omitForeignKeys: [$omitted->constraintName]);

        self::assertStringNotContainsString(
            $dialect->buildForeignKeyLine($omitted),
            $create,
            'the omitted constraint must not be emitted ('.$dialect::class.')',
        );
        foreach (\array_slice($schema->foreignKeys, 1) as $kept) {
            self::assertStringContainsString(
                $dialect->buildForeignKeyLine($kept),
                $create,
                'omitting one FK must not affect the others ('.$dialect::class.')',
            );
        }
        // The fragment the caller will hand to ALTER TABLE … ADD is still available, unchanged.
        self::assertStringContainsString($omitted->constraintName, $dialect->buildForeignKeyLine($omitted));
    }

    /** @dataProvider dialects */
    public function testOmittingNothingLeavesCreateTableByteIdentical(SqlDialect $dialect): void
    {
        $schema = TableSchema::fromClass(DdlForeignKeyRecord::class);

        self::assertSame($dialect->buildCreateTable($schema), $dialect->buildCreateTable($schema, omitForeignKeys: []));
        // A name matching no constraint is ignored rather than an error — callers pass a set
        // computed from the whole model, not from this one table.
        self::assertSame($dialect->buildCreateTable($schema), $dialect->buildCreateTable($schema, omitForeignKeys: ['fk_nonexistent']));
    }

    public function testSqliteColumnLineFragmentIsNeverTheInlinePkForm(): void
    {
        // The public fragment form renders an autoIncrement column as a plain column: the inline
        // `INTEGER PRIMARY KEY AUTOINCREMENT` rowid alias is a CREATE-TABLE-only concern.
        $schema = TableSchema::fromClass(DdlOrderRecord::class);
        $fragment = (new SqliteDialect())->buildColumnLine($schema->columns[$schema->pk]);

        self::assertStringNotContainsString('AUTOINCREMENT', $fragment);
        self::assertStringNotContainsString('PRIMARY KEY', $fragment);
    }

    public function testRenamedFromIsStoredOnTheColumnDefinition(): void
    {
        $col = TableSchema::fromClass(SeamRenameRecord::class)->column('sku');

        self::assertSame('sku_code', $col->renamedFrom);
        self::assertNull(TableSchema::fromClass(SeamPlainRecord::class)->column('sku')->renamedFrom);
    }

    /** @dataProvider dialects */
    public function testRenamedFromIsInertInGeneratedDdl(SqlDialect $dialect): void
    {
        self::assertSame(
            $dialect->buildCreateTable(TableSchema::fromClass(SeamPlainRecord::class)),
            $dialect->buildCreateTable(TableSchema::fromClass(SeamRenameRecord::class)),
            'renamedFrom must not change emitted DDL ('.$dialect::class.')',
        );
    }
}
