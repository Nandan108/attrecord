<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Session;

use Nandan108\Attrecord\DbSession;

/**
 * DbSession implementation backed by the WordPress \wpdb global.
 *
 * Converts attrecord's `?` positional placeholders to wpdb's `%s` format.
 * Existing `%` characters in the SQL (e.g. in LIKE clauses) are escaped to `%%`
 * before placeholder substitution so wpdb::prepare() does not misinterpret them.
 *
 * Errors from wpdb are surfaced as RuntimeException so isDuplicateKeyError()
 * and callers can inspect them uniformly.
 *
 * @api
 */
final class WpDbSession implements DbSession
{
    private int $txDepth = 0;

    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $this->wpdb->query($this->buildSql($sql, $params));
        $this->checkError();

        return $this->wpdb->rows_affected;
    }

    /**
     * @psalm-suppress MoreSpecificReturnType, MixedReturnTypeCoercion
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @psalm-suppress UndefinedConstant, MixedArgument */
        $rows = $this->wpdb->get_results($this->buildSql($sql, $params), ARRAY_A);
        $this->checkError();

        /** @psalm-suppress MixedReturnTypeCoercion */
        return $rows ?? [];
    }

    /**
     * @psalm-suppress MoreSpecificReturnType, MixedReturnTypeCoercion
     */
    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        /** @psalm-suppress UndefinedConstant, MixedArgument */
        $row = $this->wpdb->get_row($this->buildSql($sql, $params), ARRAY_A);
        $this->checkError();

        /** @psalm-suppress MixedReturnTypeCoercion */
        return $row;
    }

    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        /** @psalm-suppress MixedAssignment */
        $value = $this->wpdb->get_var($this->buildSql($sql, $params));
        $this->checkError();

        /** @psalm-suppress MixedReturnStatement */
        return $value;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return $this->wpdb->insert_id;
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        $isOuter = 0 === $this->txDepth;
        if ($isOuter) {
            $this->wpdb->query('START TRANSACTION');
        }
        ++$this->txDepth;
        try {
            $result = $operation();
            --$this->txDepth;
            if (0 === $this->txDepth) {
                $this->wpdb->query('COMMIT');
            }

            return $result;
        } catch (\Throwable $e) {
            --$this->txDepth;
            if (0 === $this->txDepth) {
                $this->wpdb->query('ROLLBACK');
            }
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
        return $this->txDepth > 0;
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return str_contains($throwable->getMessage(), '1062 Duplicate entry')
            || str_contains($this->wpdb->last_error, '1062 Duplicate entry');
    }

    // -----------------------------------------------------------------

    /** Build the final SQL string ready for wpdb, with placeholders substituted. */
    private function buildSql(string $sql, array $params): string
    {
        if (empty($params)) {
            return $sql;
        }

        // NULL params cannot be passed to wpdb::prepare() via %s (they become '').
        // Substitute NULL literals directly into the SQL for null params; keep ? for non-null params.
        $params = array_values($params);
        $nonNullParams = [];
        $paramIndex = 0;
        $processedSql = (string) preg_replace_callback('/\?/', function () use (&$params, &$paramIndex, &$nonNullParams): string {
            /** @psalm-suppress MixedAssignment */
            $param = $params[$paramIndex++] ?? null;
            if (null === $param) {
                return 'NULL';
            }
            /** @psalm-suppress MixedAssignment */
            $nonNullParams[] = $param;

            return '?';
        }, $sql);

        if (empty($nonNullParams)) {
            return $processedSql;
        }

        // Escape existing % (e.g. LIKE '%foo%') before wpdb interprets them as format directives.
        $escaped = str_replace('%', '%%', $processedSql);
        // Convert remaining ? positional markers to %s for wpdb::prepare().
        $wpSql = str_replace('?', '%s', $escaped);

        /** @psalm-suppress MixedReturnStatement */
        return $this->wpdb->prepare($wpSql, ...$nonNullParams);
    }

    private function checkError(): void
    {
        if ('' !== $this->wpdb->last_error) {
            throw new \RuntimeException('wpdb: '.$this->wpdb->last_error);
        }
    }
}
