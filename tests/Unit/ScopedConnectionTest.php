<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Test\CapturingDbSession;
use Nandan108\Attrecord\Tests\Fixtures\UpsertByUniqueKeyRecord;
use PHPUnit\Framework\TestCase;

/**
 * Scoped per-operation connection/session binding: {@see Record::usingConnection()} and
 * {@see Record::usingSession()}.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class ScopedConnectionTest extends TestCase
{
    private CapturingDbSession $global;
    private Connection $globalConn;

    #[\Override]
    protected function setUp(): void
    {
        $this->global = new CapturingDbSession();
        $this->globalConn = new Connection($this->global, new MysqlDialect());
        Record::setConnection($this->globalConn);
        $this->global->reset();
        TableSchema::clearCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TableSchema::clearCache();
    }

    public function testWritesInsideTheBlockGoToTheBoundConnectionAndRestoreAfter(): void
    {
        $boundSession = new CapturingDbSession();
        $boundConn = new Connection($boundSession, new MysqlDialect());

        self::assertSame($this->globalConn, UpsertByUniqueKeyRecord::connection());

        $ret = Record::usingConnection($boundConn, function () use ($boundConn): string {
            self::assertSame($boundConn, UpsertByUniqueKeyRecord::connection(), 'scoped connection wins');
            $r = new UpsertByUniqueKeyRecord();
            $r->code = 'a';
            $r->name = 'A';
            $r->save();

            return 'result';
        });

        /** @psalm-suppress RedundantConditionGivenDocblockType the generic narrows $ret to the literal, but the passthrough is still worth asserting at runtime */
        self::assertSame('result', $ret, 'the closure return value is passed through');
        self::assertSame($this->globalConn, UpsertByUniqueKeyRecord::connection(), 'binding restored after the block');
        self::assertNotEmpty($boundSession->allCalls(), 'the write landed on the bound session');
        self::assertEmpty($this->global->allCalls(), 'nothing touched the global session');
    }

    public function testBindingIsRestoredWhenTheClosureThrows(): void
    {
        $boundConn = new Connection(new CapturingDbSession(), new MysqlDialect());

        try {
            Record::usingConnection($boundConn, static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($this->globalConn, UpsertByUniqueKeyRecord::connection(), 'binding restored despite the throw');
    }

    public function testNestingRestoresToTheOuterScopeNotTheGlobal(): void
    {
        $outer = new Connection(new CapturingDbSession(), new MysqlDialect());
        $inner = new Connection(new CapturingDbSession(), new MysqlDialect());

        Record::usingConnection($outer, function () use ($outer, $inner): void {
            self::assertSame($outer, UpsertByUniqueKeyRecord::connection());
            Record::usingConnection($inner, function () use ($inner): void {
                self::assertSame($inner, UpsertByUniqueKeyRecord::connection());
            });
            self::assertSame($outer, UpsertByUniqueKeyRecord::connection(), 'inner restored to outer');
        });

        self::assertSame($this->globalConn, UpsertByUniqueKeyRecord::connection());
    }

    public function testUsingSessionBindsTheSessionAndReusesTheCurrentDialect(): void
    {
        $boundSession = new CapturingDbSession();

        Record::usingSession($boundSession, function () use ($boundSession): void {
            $conn = UpsertByUniqueKeyRecord::connection();
            self::assertSame($boundSession, $conn->session, 'session bound');
            self::assertSame($this->globalConn->dialect, $conn->dialect, 'dialect carried over from the current connection');
        });

        self::assertSame($this->globalConn, UpsertByUniqueKeyRecord::connection());
    }
}
