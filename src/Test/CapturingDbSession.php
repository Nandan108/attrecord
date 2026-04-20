<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Test;

use Nandan108\Attrecord\DbSession;

/**
 * DbSession implementation that captures SQL statements without touching a real database.
 *
 * Use in unit tests to assert generated SQL and parameters:
 *
 *   $session = new CapturingDbSession();
 *   Record::setConnection(new Connection($session, new MysqlDialect()));
 *
 *   $po = new PurchaseOrder();
 *   $po->status = 'draft';
 *   $po->save();
 *
 *   assertStringContainsString('INSERT INTO `invflux_purchase_orders`', $session->lastSql());
 *   assertSame(['draft', ...], $session->lastParams());
 *
 * @api
 *
 * @internal  test utility — do not use in production code
 */
final class CapturingDbSession implements DbSession
{
    /** @var list<array{sql: string, params: list<scalar|null>}> */
    private array $log = [];

    private int $nextInsertId = 1;

    private int $lastInsertedId = 0;

    private int $transactionDepth = 0;

    public function setNextInsertId(int $id): void
    {
        $this->nextInsertId = $id;
    }

    // -----------------------------------------------------------------
    // Inspection helpers
    // -----------------------------------------------------------------

    public function lastSql(): ?string
    {
        $last = end($this->log);

        return false !== $last ? $last['sql'] : null;
    }

    /** @return list<scalar|null>|null */
    public function lastParams(): ?array
    {
        $last = end($this->log);

        return false !== $last ? $last['params'] : null;
    }

    /** @return list<array{sql: string, params: list<scalar|null>}> */
    public function allCalls(): array
    {
        return $this->log;
    }

    public function reset(): void
    {
        $this->log = [];
    }

    // -----------------------------------------------------------------
    // DbSession implementation
    // -----------------------------------------------------------------

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $this->record($sql, $params);
        $this->lastInsertedId = $this->nextInsertId++;

        return 1; // affected rows
    }

    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->record($sql, $params);

        return [];
    }

    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->record($sql, $params);

        return null;
    }

    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        $this->record($sql, $params);

        return null;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return $this->lastInsertedId;
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        ++$this->transactionDepth;
        try {
            return $operation();
        } finally {
            --$this->transactionDepth;
        }
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return false;
    }

    // -----------------------------------------------------------------

    /** @param array<array-key, scalar|null> $params */
    private function record(string $sql, array $params): void
    {
        $this->log[] = ['sql' => $sql, 'params' => array_values($params)];
    }
}
