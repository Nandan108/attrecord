<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * A {@see DbSession} that can recognise transient transaction-conflict errors worth retrying —
 * deadlocks, serialization failures, lock-wait timeouts, `SQLITE_BUSY`, etc.
 *
 * This is a **separate, opt-in** interface rather than a method on `DbSession`, so adding retry
 * classification does not break existing custom `DbSession` implementations. attrecord's own
 * sessions implement it; {@see RetryingDbSession} uses it (falling back to an explicit predicate
 * when the wrapped session does not implement it).
 *
 * The default classification **includes deadlocks** — most applications want them retried. A
 * consumer with strict lock-order discipline that would rather surface a deadlock can pass a
 * custom predicate to {@see RetryingDbSession} instead.
 *
 * @api
 */
interface RetryableErrorClassifier
{
    /**
     * Whether the throwable is a transient transaction conflict that is safe to retry by
     * re-running the transaction (as opposed to a permanent failure like a constraint violation
     * or a syntax error).
     */
    public function isRetryableTransactionError(\Throwable $throwable): bool;
}
