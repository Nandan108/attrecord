<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;

/**
 * Shared advisory-lock cases, run against both MySQL (GET_LOCK / RELEASE_LOCK) and PostgreSQL
 * (pg_advisory_lock / pg_advisory_unlock keyed on a hash of the lock name).
 *
 * These exercise the happy path and release semantics on a single connection; true
 * cross-connection mutual exclusion is out of scope for the in-process test harness.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase
 */
trait AdvisoryLockCases
{
    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        return [];
    }

    public function testWithAdvisoryLockReturnsCallbackResult(): void
    {
        $session = Record::connection()->session;

        $result = $session->withAdvisoryLock('attrecord_test_lock', 5, static fn (): int => 42);

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame(42, $result);
    }

    public function testLockIsReleasedSoTheSameNameCanBeReacquired(): void
    {
        $session = Record::connection()->session;

        $first = $session->withAdvisoryLock('attrecord_reacquire', 5, static fn (): string => 'first');
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame('first', $first);

        // If the lock had not been released after the first callback, this would block until
        // the 5s timeout and then throw; reaching the assertion proves it was released.
        $second = $session->withAdvisoryLock('attrecord_reacquire', 5, static fn (): string => 'second');
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame('second', $second);
    }

    public function testNestedDistinctLocksBothRun(): void
    {
        $session = Record::connection()->session;

        $ran = [];
        $session->withAdvisoryLock('attrecord_outer', 5, function () use ($session, &$ran): void {
            $ran[] = 'outer';
            $session->withAdvisoryLock('attrecord_inner', 5, function () use (&$ran): void {
                $ran[] = 'inner';
            });
        });

        $this->assertSame(['outer', 'inner'], $ran);
    }
}
