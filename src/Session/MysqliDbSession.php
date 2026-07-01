<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Session;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\RetryableErrorClassifier;

/**
 * DbSession implementation backed by a PHP mysqli connection.
 *
 * Requires PHP 8.1+ for the array form of mysqli_stmt::execute().
 * Nested transactional() calls are tracked via an internal depth counter;
 * only the outermost call issues BEGIN / COMMIT / ROLLBACK.
 *
 * @api
 */
final class MysqliDbSession implements DbSession, RetryableErrorClassifier
{
    private int $txDepth = 0;

    public function __construct(private readonly \mysqli $conn)
    {
    }

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($this->bindValues($params));
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($this->bindValues($params));
        $result = $stmt->get_result();

        /** @var list<array<string, scalar|null>> $rows */
        $rows = false !== $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows;
    }

    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($this->bindValues($params));
        $result = $stmt->get_result();
        $row = false !== $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?? null;
    }

    /**
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        $row = $this->fetchOne($sql, $params);
        if (null === $row) {
            return null;
        }
        $value = reset($row);

        return false !== $value ? $value : null;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return $this->conn->insert_id;
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        $isOuter = 0 === $this->txDepth;
        if ($isOuter) {
            $this->conn->begin_transaction();
        }
        ++$this->txDepth;
        try {
            $result = $operation();
            --$this->txDepth;
            if (0 === $this->txDepth) {
                $this->conn->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            --$this->txDepth;
            if (0 === $this->txDepth) {
                $this->conn->rollback();
            }
            throw $e;
        }
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
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

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        // Prefer the thrown exception's code: a mysqli_sql_exception from a *prepared-statement*
        // failure carries errno 1062, whereas $this->conn->errno is not reliably populated for
        // statement-level errors across MySQL/MariaDB versions. The message is "Duplicate entry
        // '…' for key '…'" (no numeric prefix), so match on that phrase, not "1062 …".
        return 1062 === $throwable->getCode()
            || 1062 === $this->conn->errno
            || str_contains($throwable->getMessage(), 'Duplicate entry');
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        // mysqli_sql_exception carries the MySQL errno via getCode(): 1213 deadlock, 1205
        // lock-wait timeout, 1020 MariaDB "record has changed since last read".
        return \in_array($throwable->getCode(), [1213, 1205, 1020], true)
            || \in_array($this->conn->errno, [1213, 1205, 1020], true);
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->conn->prepare($sql);
        if (false === $stmt) {
            throw new \RuntimeException('mysqli prepare failed: '.$this->conn->error);
        }

        return $stmt;
    }

    /**
     * Normalise the bound parameters for mysqli_stmt::execute(): unwrap {@see BinaryParam} to
     * its raw byte string (MySQL binds binary data through an ordinary string parameter).
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return list<scalar|null>
     */
    private function bindValues(array $params): array
    {
        return \array_map(
            static fn (mixed $v): mixed => $v instanceof BinaryParam ? $v->bytes : $v,
            \array_values($params),
        );
    }
}
