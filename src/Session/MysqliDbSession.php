<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Session;

use Nandan108\Attrecord\DbSession;

/**
 * DbSession implementation backed by a PHP mysqli connection.
 *
 * Requires PHP 8.1+ for the array form of mysqli_stmt::execute().
 * Nested transactional() calls are tracked via an internal depth counter;
 * only the outermost call issues BEGIN / COMMIT / ROLLBACK.
 *
 * @api
 */
final class MysqliDbSession implements DbSession
{
    private int $txDepth = 0;

    public function __construct(private readonly \mysqli $conn)
    {
    }

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql);
        $stmt->execute(array_values($params));
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepare($sql);
        $stmt->execute(array_values($params));
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
        $stmt->execute(array_values($params));
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
    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return 1062 === $this->conn->errno
            || str_contains($throwable->getMessage(), '1062 Duplicate entry');
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->conn->prepare($sql);
        if (false === $stmt) {
            throw new \RuntimeException('mysqli prepare failed: '.$this->conn->error);
        }

        return $stmt;
    }
}
