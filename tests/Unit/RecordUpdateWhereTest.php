<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use Nandan108\Attrecord\WhereClause;
use PHPUnit\Framework\TestCase;

/**
 * Covers the static bulk-update path: SET-clause assembly, parameter ordering,
 * and the {@see RawSql} escape hatch (with and without bound params).
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class RecordUpdateWhereTest extends TestCase
{
    private CapturingDbSession $session;

    protected function setUp(): void
    {
        $this->session = new CapturingDbSession();
        Record::setConnection(new Connection($this->session, new MysqlDialect()));
        TableSchema::clearCache();
    }

    public function testScalarSetGeneratesParameterisedUpdate(): void
    {
        UserRecord::updateWhere(
            ['name' => 'Bob'],
            '`id` = ?',
            [42],
        );

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('UPDATE `attrecord_users` SET `name` = ?', $sql);
        $this->assertStringContainsString('WHERE `id` = ?', $sql);
        $this->assertSame(['Bob', 42], $this->session->lastParams());
    }

    public function testRawSqlExpressionInlinedWithoutParameter(): void
    {
        // No bound params on the RawSql — expression embedded verbatim
        UserRecord::updateWhere(
            ['name' => new RawSql('UPPER(`name`)')],
            '`id` = ?',
            [42],
        );

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('SET `name` = UPPER(`name`)', $sql);
        $this->assertStringNotContainsString('SET `name` = ?', $sql);
        // Only the WHERE param is bound
        $this->assertSame([42], $this->session->lastParams());
    }

    public function testRawSqlParamsAreMergedBeforeWhereParams(): void
    {
        // Parameterised RawSql in SET — its params come before the WHERE params
        UserRecord::updateWhere(
            ['name' => new RawSql('CONCAT(?, `name`, ?)', ['<', '>'])],
            '`id` = ?',
            [42],
        );

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('SET `name` = CONCAT(?, `name`, ?)', $sql);
        $this->assertSame(['<', '>', 42], $this->session->lastParams());
    }

    public function testMixedScalarAndRawSqlSetPreservesOrder(): void
    {
        // SET parts contribute params in declaration order; WHERE params appended after
        UserRecord::updateWhere(
            [
                'name'   => 'Bob',                                   // ? — 'Bob'
                'email'  => new RawSql('LOWER(?)', ['ALICE@X']),     // ? — 'ALICE@X'
                'active' => true,                                    // ? — true → 1 via ColumnSerializer (Bool column)
            ],
            '`id` IN (?, ?)',
            [1, 2],
        );

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString(
            'SET `name` = ?, `email` = LOWER(?), `active` = ?',
            $sql,
        );
        $this->assertSame(['Bob', 'ALICE@X', 1, 1, 2], $this->session->lastParams());
    }

    public function testWhereClauseObjectAccepted(): void
    {
        UserRecord::updateWhere(
            ['name' => 'Bob'],
            WhereClause::where('id', 42),
        );

        $sql = $this->session->lastSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('WHERE (`id` = ?)', $sql);
        $this->assertSame(['Bob', 42], $this->session->lastParams());
    }

    public function testEmptySetIsNoop(): void
    {
        $affected = UserRecord::updateWhere([], '`id` = ?', [42]);

        $this->assertSame(0, $affected);
        $this->assertNull($this->session->lastSql());
    }

    public function testUnknownColumnInSetThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        UserRecord::updateWhere(['nope' => 'x'], '`id` = ?', [1]);
    }
}
