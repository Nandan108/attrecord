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
     * @param array<array-key, scalar|null> $params
     */
    public function exec(string $sql, array $params = []): int;

    /**
     * Fetch all rows for a read query.
     *
     * @param array<array-key, scalar|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Fetch the first row for a read query, or null when no row matches.
     *
     * @param array<array-key, scalar|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Fetch the first column of the first row, or null when no row matches.
     *
     * @param array<array-key, scalar|null> $params
     */
    public function fetchScalar(string $sql, array $params = []): string | int | float | null;

    /** Return the last generated auto-increment id for this session. */
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
     * Uses MySQL GET_LOCK / RELEASE_LOCK. Advisory locks are connection-scoped and do not
     * interact with table or row locks. Safe to nest inside a transaction.
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
}
