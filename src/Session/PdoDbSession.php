<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Session;

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
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));

        return $stmt->rowCount();
    }

    /**
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));

        return $stmt->fetchAll();
    }

    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        /** @psalm-suppress MixedAssignment */
        $row = $stmt->fetch();

        /** @psalm-suppress MixedReturnStatement */
        return false !== $row ? $row : null;
    }

    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        /** @psalm-suppress MixedAssignment */
        $value = $stmt->fetchColumn();

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
        $acquired = $this->fetchScalar('SELECT GET_LOCK(?, ?)', [$lockName, $timeoutSeconds]);
        if (1 !== (int) $acquired) {
            throw new \RuntimeException(sprintf('Could not acquire advisory lock "%s" within %d second(s).', $lockName, $timeoutSeconds));
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
        return $this->pdo->inTransaction();
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return $throwable instanceof \PDOException && '23000' === $throwable->getCode();
    }
}
