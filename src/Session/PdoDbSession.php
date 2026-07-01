<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Session;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\DbSession;

/**
 * DbSession implementation backed by a PHP PDO connection.
 *
 * Compatible with any PDO-supported database (MySQL, MariaDB, PostgreSQL, SQLite, …).
 * Nested transactional() calls are passed through to the outer transaction.
 *
 * @api
 */
final class PdoDbSession implements DbSession
{
    private readonly string $driver;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        /** @var string $driver */
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = $driver;
    }

    /**
     * Prepare $sql and bind $params positionally, giving each value its PDO type.
     *
     * {@see BinaryParam} binds as PDO::PARAM_LOB so raw bytes reach a bytea/BLOB column
     * intact — PDO_pgsql otherwise treats a positional parameter as UTF-8 text and rejects
     * non-UTF-8 bytes. All other scalars bind as strings (the prior behaviour), which both
     * MySQL and PostgreSQL coerce to the target column type.
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     */
    private function prepareBound(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach (\array_values($params) as $value) {
            if ($value instanceof BinaryParam) {
                $stmt->bindValue($i, $value->bytes, \PDO::PARAM_LOB);
            } elseif (null === $value) {
                $stmt->bindValue($i, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($i, (string) $value, \PDO::PARAM_STR);
            }
            ++$i;
        }
        $stmt->execute();

        return $stmt;
    }

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        return $this->prepareBound($sql, $params)->rowCount();
    }

    /**
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->prepareBound($sql, $params)->fetchAll();
    }

    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        /** @psalm-suppress MixedAssignment */
        $row = $this->prepareBound($sql, $params)->fetch();

        /** @psalm-suppress MixedReturnStatement */
        return false !== $row ? $row : null;
    }

    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        /** @psalm-suppress MixedAssignment */
        $value = $this->prepareBound($sql, $params)->fetchColumn();

        /** @psalm-suppress MixedReturnStatement */
        return false !== $value ? $value : null;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return (int) $this->pdo->lastInsertId();
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            // Already inside an outer transaction — run inline; let the outer commit/rollback.
            return $operation();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        if ('pgsql' === $this->driver) {
            return $this->withPgAdvisoryLock($lockName, $timeoutSeconds, $callback);
        }

        $acquired = $this->fetchScalar('SELECT GET_LOCK(?, ?)', [$lockName, $timeoutSeconds]);
        if (1 !== (int) $acquired) {
            throw new \RuntimeException(\sprintf('Could not acquire advisory lock "%s" within %d second(s).', $lockName, $timeoutSeconds));
        }
        try {
            return $callback();
        } finally {
            $this->fetchScalar('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * PostgreSQL advisory-lock variant. pg_advisory_lock keys are bigint, not strings, so the
     * lock name is hashed to a stable signed-32-bit key via crc32. PostgreSQL has no built-in
     * wait timeout for advisory locks, so a positive $timeoutSeconds is emulated by polling
     * pg_try_advisory_lock; 0 tries once, a negative value waits indefinitely via the blocking
     * pg_advisory_lock. Locks are connection-scoped and released with pg_advisory_unlock.
     *
     * @template TResult
     *
     * @param \Closure(): TResult $callback
     *
     * @return TResult
     */
    private function withPgAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        // crc32 → unsigned 32-bit; shift into signed range so it fits PostgreSQL's bigint key.
        $key = \crc32($lockName) - 2_147_483_648;

        // The pg_*advisory* functions return boolean/void; cast to int so the value flows
        // through fetchScalar (which is typed for string|int|float|null, not bool).
        $acquired = false;
        if ($timeoutSeconds < 0) {
            $this->fetchScalar('SELECT pg_advisory_lock(?)::text', [$key]);
            $acquired = true;
        } else {
            $deadline = \microtime(true) + (float) $timeoutSeconds;
            while (true) {
                $acquired = 1 === (int) $this->fetchScalar('SELECT pg_try_advisory_lock(?)::int', [$key]);
                if ($acquired || \microtime(true) >= $deadline) {
                    break;
                }
                \usleep(50_000);
            }
        }

        if (!$acquired) {
            throw new \RuntimeException(\sprintf('Could not acquire advisory lock "%s" within %d second(s).', $lockName, $timeoutSeconds));
        }

        try {
            return $callback();
        } finally {
            $this->fetchScalar('SELECT pg_advisory_unlock(?)::int', [$key]);
        }
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        if (!$throwable instanceof \PDOException) {
            return false;
        }

        $code = (string) $throwable->getCode();

        // MySQL/MariaDB report the generic integrity-violation SQLSTATE 23000 for a duplicate
        // key; PostgreSQL reports the specific unique_violation code 23505.
        return '23000' === $code || '23505' === $code;
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        if (!$throwable instanceof \PDOException) {
            return false;
        }

        $sqlState = (string) $throwable->getCode();
        /** @var array{0?: string, 1?: int|string, 2?: string}|null $info */
        $info = $throwable->errorInfo;
        $driverCode = \is_array($info) && isset($info[1]) ? (int) $info[1] : null;

        return match ($this->driver) {
            // 40001 serialization_failure, 40P01 deadlock_detected.
            'pgsql' => \in_array($sqlState, ['40001', '40P01'], true),
            // SQLITE_BUSY (5) / SQLITE_LOCKED (6); message is the reliable cross-version signal.
            'sqlite' => \in_array($driverCode, [5, 6], true) || \str_contains($throwable->getMessage(), 'locked'),
            // MySQL/MariaDB: 1213 deadlock, 1205 lock-wait timeout, 1020 MariaDB MVCC re-read.
            default => \in_array($driverCode, [1213, 1205, 1020], true),
        };
    }
}
