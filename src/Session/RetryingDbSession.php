<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Session;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\RetryableErrorClassifier;

/**
 * A {@see DbSession} decorator that retries the **outer** transaction on transient conflicts
 * (deadlock / serialization failure / lock-wait timeout / `SQLITE_BUSY`) with exponential
 * backoff + jitter. Opt-in and prunable: wrap a session with it only where you want retries.
 *
 * ```php
 * $conn = new Connection(new RetryingDbSession(new PdoDbSession($pdo)), new PgsqlDialect());
 * ```
 *
 * Every method except {@see transactional()} delegates verbatim to the wrapped session, so
 * `Record::transactional()` and `RecordSet::saveAll()` gain retries automatically (they funnel
 * through `transactional()`). Nested transactional() calls run inline — only the outermost is
 * retried.
 *
 * **Idempotency contract:** the closure is *re-run* on each attempt. Any effect inside it that
 * the database does not roll back — HTTP calls, queue publishes, file writes, in-memory mutation
 * — will repeat. Closures passed to `transactional()` under a RetryingDbSession must be safe to
 * re-run (pure-SQL, or side-effect-free outside the DB).
 *
 * Which errors count as retryable comes from `$retryable` if given, else from the wrapped
 * session when it implements {@see RetryableErrorClassifier} (attrecord's sessions do). If
 * neither applies, nothing is retried.
 *
 * @api
 */
final class RetryingDbSession implements DbSession
{
    /** @var (\Closure(\Throwable): bool)|null */
    private readonly ?\Closure $retryable;

    /**
     * @param DbSession                         $inner       the session to wrap
     * @param int                               $maxAttempts total attempts, including the first (>= 1)
     * @param int                               $baseDelayUs base backoff in microseconds (doubled per attempt)
     * @param int                               $maxDelayUs  per-attempt backoff cap in microseconds
     * @param (\Closure(\Throwable): bool)|null $retryable   overrides the wrapped session's classification
     */
    public function __construct(
        private readonly DbSession $inner,
        private readonly int $maxAttempts = 10,
        private readonly int $baseDelayUs = 5_000,
        private readonly int $maxDelayUs = 100_000,
        ?\Closure $retryable = null,
    ) {
        $this->retryable = $retryable;
    }

    /**
     * @template TResult
     *
     * @param \Closure(): TResult $operation
     *
     * @return TResult
     */
    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        // Only the outermost transaction is retried; a nested call runs inline in the outer one.
        if ($this->inner->inTransaction()) {
            return $this->inner->transactional($operation);
        }

        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->inner->transactional($operation);
            } catch (\Throwable $e) {
                if ($attempt >= $this->maxAttempts || !$this->isRetryable($e)) {
                    throw $e;
                }
                $this->backoff($attempt);
            }
        }
    }

    private function isRetryable(\Throwable $throwable): bool
    {
        if (null !== $this->retryable) {
            return ($this->retryable)($throwable);
        }

        return $this->inner instanceof RetryableErrorClassifier
            && $this->inner->isRetryableTransactionError($throwable);
    }

    /** Exponential backoff (capped) with up-to-50% jitter. */
    private function backoff(int $attempt): void
    {
        $delay = \min($this->maxDelayUs, $this->baseDelayUs * (2 ** ($attempt - 1)));
        $jitter = (int) ((float) $delay * 0.5 * ((float) \mt_rand() / (float) \mt_getrandmax()));
        \usleep($delay + $jitter);
    }

    // ---- everything else delegates verbatim to the wrapped session ----

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        return $this->inner->exec($sql, $params);
    }

    /**
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->inner->fetchAll($sql, $params);
    }

    /**
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->inner->fetchOne($sql, $params);
    }

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        return $this->inner->fetchScalar($sql, $params);
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return $this->inner->lastInsertId();
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $this->inner->withAdvisoryLock($lockName, $timeoutSeconds, $callback);
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return $this->inner->isDuplicateKeyError($throwable);
    }
}
