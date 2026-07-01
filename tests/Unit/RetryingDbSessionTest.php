<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Session\RetryingDbSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers the RetryingDbSession decorator: outer-transaction retry on classified transient errors,
 * exponential backoff, give-up after maxAttempts, non-retryable pass-through, nested pass-through,
 * the override predicate, and delegation of the non-transactional methods.
 */
final class RetryingDbSessionTest extends TestCase
{
    /** @param (\Closure(\Throwable): bool)|null $retryable */
    private function wrap(FlakyDbSession $inner, int $maxAttempts = 5, ?\Closure $retryable = null): RetryingDbSession
    {
        // baseDelayUs = 1 keeps the backoff negligible for the test.
        return new RetryingDbSession($inner, maxAttempts: $maxAttempts, baseDelayUs: 1, retryable: $retryable);
    }

    public function testRetriesUntilSuccess(): void
    {
        $inner = new FlakyDbSession(failTimes: 2);
        $result = $this->wrap($inner)->transactional(static fn (): string => 'RESULT');

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame('RESULT', $result);
        $this->assertSame(3, $inner->attempts); // 2 failures + 1 success
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $inner = new FlakyDbSession(failTimes: 100);

        try {
            $this->wrap($inner, maxAttempts: 3)->transactional(static fn (): string => 'never');
            $this->fail('expected the retryable error to surface after exhausting attempts');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RETRYABLE', $e->getMessage());
        }
        $this->assertSame(3, $inner->attempts);
    }

    public function testNonRetryableErrorIsNotRetried(): void
    {
        $inner = new FlakyDbSession(failTimes: 100, retryable: false);

        $this->expectException(\RuntimeException::class);
        try {
            $this->wrap($inner, maxAttempts: 5)->transactional(static fn (): string => 'never');
        } finally {
            $this->assertSame(1, $inner->attempts); // failed once, not retried
        }
    }

    public function testNestedTransactionIsNotRetried(): void
    {
        $inner = new FlakyDbSession(failTimes: 100);
        $inner->inTx = true; // simulate being inside an outer transaction

        try {
            $this->wrap($inner)->transactional(static fn (): string => 'never');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $inner->attempts); // passed straight through, no retry loop
    }

    public function testOverridePredicateWins(): void
    {
        // The session classifies its failures as NOT retryable, but the override says otherwise.
        $inner = new FlakyDbSession(failTimes: 1, retryable: false);
        $result = $this->wrap($inner, retryable: static fn (\Throwable $e): bool => '' !== $e->getMessage())
            ->transactional(static fn (): string => 'RESULT');

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame('RESULT', $result);
        $this->assertSame(2, $inner->attempts);
    }

    public function testDelegatesNonTransactionalMethods(): void
    {
        $inner = new FlakyDbSession(failTimes: 0);
        $session = $this->wrap($inner);

        $this->assertSame(1, $session->exec('DELETE FROM t'));
        $this->assertSame(1, $inner->execCalls);
        $this->assertFalse($session->inTransaction());
        $this->assertSame([], $session->fetchAll('SELECT 1'));
    }
}

/** @internal Controllable DbSession that fails transactional() a configurable number of times. */
final class FlakyDbSession implements DbSession
{
    public int $attempts = 0;
    public int $execCalls = 0;
    public bool $inTx = false;

    public function __construct(
        private readonly int $failTimes,
        private readonly bool $retryable = true,
    ) {
    }

    public function transactional(\Closure $operation): mixed
    {
        ++$this->attempts;
        if ($this->attempts <= $this->failTimes) {
            throw new \RuntimeException($this->retryable ? 'RETRYABLE conflict' : 'FATAL error');
        }

        return $operation();
    }

    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        return str_contains($throwable->getMessage(), 'RETRYABLE');
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function exec(string $sql, array $params = []): int
    {
        ++$this->execCalls;

        return 1;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return [];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return null;
    }

    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        return null;
    }

    public function lastInsertId(): string | int
    {
        return 0;
    }

    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $callback();
    }

    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return false;
    }
}
