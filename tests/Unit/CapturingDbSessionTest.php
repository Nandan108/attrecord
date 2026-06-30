<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Test\CapturingDbSession;
use PHPUnit\Framework\TestCase;

/** Covers the CapturingDbSession test double's own surface. */
final class CapturingDbSessionTest extends TestCase
{
    public function testRecordsSqlAndParamsAndExposesInspectors(): void
    {
        $session = new CapturingDbSession();

        $this->assertNull($session->lastSql());
        $this->assertNull($session->lastParams());

        $session->exec('INSERT INTO `t` (`a`) VALUES (?)', ['x']);
        $session->exec('UPDATE `t` SET `a` = ? WHERE `id` = ?', ['y', 1]);

        $this->assertSame('UPDATE `t` SET `a` = ? WHERE `id` = ?', $session->lastSql());
        $this->assertSame(['y', 1], $session->lastParams());
        $this->assertCount(2, $session->allCalls());

        $session->reset();
        $this->assertNull($session->lastSql());
        $this->assertSame([], $session->allCalls());
    }

    public function testInsertIdAdvancesAndIsConfigurable(): void
    {
        $session = new CapturingDbSession();
        $session->setNextInsertId(100);

        $session->exec('INSERT INTO `t` VALUES (1)');
        $this->assertSame(100, $session->lastInsertId());

        $session->exec('INSERT INTO `t` VALUES (2)');
        $this->assertSame(101, $session->lastInsertId());
    }

    public function testReadsReturnEmptyShapes(): void
    {
        $session = new CapturingDbSession();

        $this->assertSame([], $session->fetchAll('SELECT 1'));
        $this->assertNull($session->fetchOne('SELECT 1'));
        $this->assertNull($session->fetchScalar('SELECT 1'));
        $this->assertCount(3, $session->allCalls());
    }

    public function testTransactionalTracksDepthAndReturnsResult(): void
    {
        $session = new CapturingDbSession();

        $this->assertFalse($session->inTransaction());
        $result = $session->transactional(function () use ($session): string {
            $this->assertTrue($session->inTransaction());

            return 'done';
        });

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame('done', $result);
        $this->assertFalse($session->inTransaction());
    }

    public function testAdvisoryLockRunsCallbackAndDuplicateKeyIsAlwaysFalse(): void
    {
        $session = new CapturingDbSession();

        $this->assertSame(7, $session->withAdvisoryLock('x', 1, static fn (): int => 7));
        $this->assertFalse($session->isDuplicateKeyError(new \RuntimeException('1062 Duplicate entry')));
    }
}
