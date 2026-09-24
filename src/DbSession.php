<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * Executes SQL statements behind one connection/session abstraction.
 *
 * Both `?` positional and `:named` placeholders are accepted; NamedPlaceholderSql
 * normalises named placeholders to positional before dispatch.
 *
 * @api
 */
interface DbSession
{
    /**
     * Execute a write statement (INSERT / UPDATE / DELETE).
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     */
    public function exec(string $sql, array $params = []): int;

    /**
     * Fetch all rows for a read query.
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Fetch the first row for a read query, or null when no row matches.
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Fetch the first column of the first row, or null when no row matches.
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     */
    public function fetchScalar(string $sql, array $params = []): string | int | float | null;

    /**
     * Return the last generated auto-increment id for this session.
     *
     * **For a multi-row INSERT, attrecord reads this as the FIRST id of the batch**, and derives
     * the rest as `first + 0 … first + n-1` (see `RecordSet`'s insert path). That is a MySQL and
     * MariaDB guarantee — `LAST_INSERT_ID()` reports the first id of a multi-row insert, and the
     * range is contiguous for a single statement — and it is not a property of the SQL standard.
     *
     * SQLite reports the **last** rowid instead, and PostgreSQL has no meaningful answer without a
     * sequence name. Both are handled by never taking this path: {@see SqlDialect::supportsReturning()}
     * is true for those dialects, so ids come back from `RETURNING` and every id is read directly.
     *
     * **The trap is a session whose dialect says MySQL while the backend is something else** — a
     * translator, a proxy, a compatibility layer. `lastInsertId()` then answers honestly for the
     * real engine, attrecord interprets it as MySQL's first-of-batch, and a batch of n rows is
     * back-filled with ids `last … last + n-1`: every row but one carries the id of a different
     * row. Nothing errors. The symptom is rows related to the wrong parent.
     *
     * A session in that position must either normalise here (MySQL's answer is
     * `insert_id - rows_affected + 1` when the driver reports the last id) or present a dialect
     * whose `supportsReturning()` is true.
     */
    public function lastInsertId(): string | int;

    /**
     * Execute a callback inside a transaction, joining an existing outer transaction when
     * present (savepoint emulation for nested calls).
     *
     * @template TResult
     *
     * @param \Closure(): TResult $operation
     *
     * @return TResult
     */
    public function transactional(\Closure $operation): mixed;

    /**
     * Acquire a named advisory lock, execute a callback, then release the lock.
     *
     * Backed by MySQL GET_LOCK / RELEASE_LOCK or, on a PostgreSQL PDO connection, by
     * pg_advisory_lock keyed on a hash of $lockName (see PdoDbSession). Advisory locks are
     * connection-scoped and do not interact with table or row locks. Safe to nest inside a
     * transaction.
     *
     * $timeoutSeconds controls the wait: 0 = fail immediately if unavailable,
     * positive = wait up to N seconds, -1 = wait indefinitely.
     *
     * Throws when the lock cannot be acquired within the timeout.
     *
     * @template TResult
     *
     * @param \Closure(): TResult $callback
     *
     * @return TResult
     */
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed;

    /** Return whether this session is currently inside a transaction. */
    public function inTransaction(): bool;

    /** Return whether the given throwable represents a duplicate-key violation. */
    public function isDuplicateKeyError(\Throwable $throwable): bool;

    /**
     * Return whether the given throwable is a transient transaction conflict that is safe to
     * retry by re-running the transaction — a deadlock, serialization failure, lock-wait
     * timeout, `SQLITE_BUSY`, etc. (as opposed to a permanent failure like a constraint
     * violation or a syntax error).
     *
     * The default classification should **include deadlocks** — most applications want them
     * retried. Used by {@see Session\RetryingDbSession}; a consumer with strict lock-order
     * discipline that would rather surface a deadlock can pass an override predicate there.
     */
    public function isRetryableTransactionError(\Throwable $throwable): bool;
}
