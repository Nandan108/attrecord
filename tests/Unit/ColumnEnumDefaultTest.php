<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\EnumDefaultRecord;
use PHPUnit\Framework\TestCase;

/**
 * `#[Column(default: SomeEnum::Case)]` — a backed enum case as a column default.
 *
 * The case is unwrapped to its backing value where the attribute becomes a
 * ColumnDefinition, so nothing downstream (ColumnDefinition, any dialect, the DDL
 * producer) ever sees an enum. Two reasons it exists:
 *
 *  1. The attribute names the vocabulary that owns the value rather than restating it, so a
 *     renumbered case cannot leave a stale default behind.
 *  2. It is the only form available below PHP 8.2. `SomeEnum::Case->value` is a property
 *     fetch, which is not a valid constant expression before 8.2 — writing it in an
 *     attribute makes the entire class unparseable on 8.1, at load time, with a fatal.
 */
final class ColumnEnumDefaultTest extends TestCase
{
    public function testEnumCaseDefaultUnwrapsToItsBackingValue(): void
    {
        $schema = TableSchema::fromClass(EnumDefaultRecord::class);
        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->propertyName] = $column;
        }

        self::assertSame('write', $columns['via_enum_case']->default);
        self::assertSame(2, $columns['int_backed']->default);
    }

    public function testEnumCaseDefaultIsIndistinguishableFromTheLiteral(): void
    {
        $schema = TableSchema::fromClass(EnumDefaultRecord::class);
        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->propertyName] = $column;
        }

        self::assertSame(
            $columns['via_literal']->default,
            $columns['via_enum_case']->default,
        );
    }

    public function testEmittedDdlCarriesTheBackingValueNotTheCaseName(): void
    {
        $sql = (new MysqlDialect())->buildCreateTable(TableSchema::fromClass(EnumDefaultRecord::class));

        self::assertStringContainsString('`via_enum_case`', $sql);
        self::assertStringContainsString("DEFAULT 'write'", $sql);
        self::assertStringContainsString('DEFAULT 2', $sql);
        // The case name must never reach SQL.
        self::assertStringNotContainsString('Write', $sql);
        self::assertStringNotContainsString('Success', $sql);
    }
}
