<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use PHPUnit\Framework\TestCase;

/**
 * The row-locking clause is dialect-provided so the FOR UPDATE reads (find/getOne forUpdate,
 * LockSet) can be gated per engine. MySQL/MariaDB and PostgreSQL both support it.
 */
final class ForUpdateClauseTest extends TestCase
{
    public function testMysqlAndPgsqlEmitForUpdate(): void
    {
        $this->assertSame('FOR UPDATE', (new MysqlDialect())->forUpdateClause());
        $this->assertSame('FOR UPDATE', (new PgsqlDialect())->forUpdateClause());
    }
}
