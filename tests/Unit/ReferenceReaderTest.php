<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Schema\Reference\MysqlReferenceReader;
use Nandan108\Attrecord\Schema\Reference\PgsqlReferenceReader;
use Nandan108\Attrecord\Schema\Reference\ReferenceReaders;
use Nandan108\Attrecord\Schema\Reference\SqliteReferenceReader;
use Nandan108\Attrecord\SqlDialect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of {@see \Nandan108\Attrecord\Schema\ReferenceReader} that are about *how it asks*
 * rather than what the catalogue answers: caching, the shape of the bulk query, and dialect
 * resolution. The answers themselves are pinned against real engines by the integration cases.
 */
final class ReferenceReaderTest extends TestCase
{
    #[Test]
    public function theCatalogueIsReadOncePerTableAndColumn(): void
    {
        // The reason this matters: an information_schema scan is slow on a server with thousands of
        // tables — ordinary shared hosting — and the answer only changes when the schema does. A
        // caller looping over rows must not re-ask.
        $session = new ScriptedSession([]);
        $reader = new MysqlReferenceReader(new MysqlDialect());

        $reader->inboundForeignKeys($session, 'parties');
        $reader->inboundForeignKeys($session, 'parties');
        $reader->inboundForeignKeys($session, 'parties');

        self::assertCount(1, $session->queries);
    }

    #[Test]
    public function adifferentColumnIsADifferentQuestion(): void
    {
        $session = new ScriptedSession([]);
        $reader = new MysqlReferenceReader(new MysqlDialect());

        $reader->inboundForeignKeys($session, 'parties');
        $reader->inboundForeignKeys($session, 'parties', 'content_hash');
        $reader->inboundForeignKeys($session, 'parties', 'content_hash');

        self::assertCount(2, $session->queries, 'the whole-table and per-column answers are cached apart');
    }

    #[Test]
    public function everyKeyIsAnsweredInOneStatement(): void
    {
        // The bulk promise, made concrete: two referring columns and three keys are one query, not
        // six. A per-key implementation would pass every other test in this file.
        $session = new ScriptedSession(
            [
                ['TABLE_NAME' => 'docs', 'COLUMN_NAME' => 'buyer_id', 'CONSTRAINT_NAME' => 'fk_a', 'REFERENCED_COLUMN_NAME' => 'hash', 'DELETE_RULE' => 'RESTRICT'],
                ['TABLE_NAME' => 'docs', 'COLUMN_NAME' => 'ship_to_id', 'CONSTRAINT_NAME' => 'fk_b', 'REFERENCED_COLUMN_NAME' => 'hash', 'DELETE_RULE' => 'SET NULL'],
            ],
            [['k' => 'a'], ['k' => 'c']],
        );
        $reader = new MysqlReferenceReader(new MysqlDialect());

        $found = $reader->referencedKeys($session, 'parties', 'hash', ['a', 'b', 'c']);

        self::assertSame(['a', 'c'], $found);
        self::assertCount(2, $session->queries, 'one catalogue read, one data read');

        $union = $session->queries[1];
        self::assertStringContainsString(' UNION ', $union['sql']);
        self::assertSame(2, substr_count($union['sql'], 'IN (?, ?, ?)'), 'one branch per referring column');
        self::assertSame(['a', 'b', 'c', 'a', 'b', 'c'], $union['params'], 'the key set is bound per branch');
    }

    #[Test]
    public function nothingIsAskedWhenThereIsNothingToAsk(): void
    {
        $session = new ScriptedSession([]);
        $reader = new MysqlReferenceReader(new MysqlDialect());

        // No keys: not even the catalogue is worth reading.
        self::assertSame([], $reader->referencedKeys($session, 'parties', 'hash', []));
        self::assertCount(0, $session->queries);

        // Keys, but nothing references the table: the catalogue settles it, so no data query runs.
        self::assertSame([], $reader->referencedKeys($session, 'parties', 'hash', ['a']));
        self::assertCount(1, $session->queries);
    }

    #[Test]
    public function theCallersOwnValuesComeBackNotTheDriversRenderingOfThem(): void
    {
        // mysqli returns a signed BIGINT column as a numeric *string* whatever was bound. Returning
        // that would break `in_array($key, $result, true)` and `array_flip()` — both natural things
        // to do with a key set — and each would report a referenced row as free to delete, which on
        // a delete screen is the wrong direction to be wrong in.
        $key = -6612819186026909909;
        $session = new ScriptedSession(
            [['TABLE_NAME' => 'docs', 'COLUMN_NAME' => 'party_id', 'CONSTRAINT_NAME' => 'fk', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT']],
            [['k' => '-6612819186026909909']],   // the string the driver hands back
        );

        $found = (new MysqlReferenceReader(new MysqlDialect()))->referencedKeys($session, 'parties', 'id', [$key, 7]);

        // assertSame compares types, so this is the whole claim: the int that was asked about,
        // not the string the driver rendered it as.
        self::assertSame([$key], $found);
    }

    #[Test]
    public function theResultKeepsTheOrderTheKeysWereGivenIn(): void
    {
        $session = new ScriptedSession(
            [['TABLE_NAME' => 'docs', 'COLUMN_NAME' => 'party_id', 'CONSTRAINT_NAME' => 'fk', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT']],
            [['k' => 'c'], ['k' => 'a']],   // the engine's order, which is nobody's order
        );

        $found = (new MysqlReferenceReader(new MysqlDialect()))->referencedKeys($session, 'parties', 'id', ['a', 'b', 'c']);

        self::assertSame(['a', 'c'], $found);
    }

    #[Test]
    public function alargeKeySetIsSplitToStayUnderTheParameterLimit(): void
    {
        // A UNION binds the key set once per referring column, so the statement costs
        // referrers × keys placeholders — and every supported engine caps that at 16 bits or less
        // (SQLite lower still). Past the cap the statement does not run slowly, it fails.
        $keys = range(1, 25_000);
        $session = new ScriptedSession(
            [
                ['TABLE_NAME' => 'docs', 'COLUMN_NAME' => 'buyer_id', 'CONSTRAINT_NAME' => 'fk_a', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT'],
                ['TABLE_NAME' => 'docs', 'COLUMN_NAME' => 'ship_to_id', 'CONSTRAINT_NAME' => 'fk_b', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT'],
            ],
        );

        (new MysqlReferenceReader(new MysqlDialect()))->referencedKeys($session, 'parties', 'id', $keys);

        $dataQueries = \array_slice($session->queries, 1);
        self::assertGreaterThan(1, \count($dataQueries), '50 000 placeholders cannot be one statement');
        foreach ($dataQueries as $query) {
            self::assertLessThanOrEqual(20_000, \count($query['params']));
        }
        // Every key still asked about exactly once: 2 referrers × 25 000 keys.
        self::assertSame(50_000, array_sum(array_map(static fn (array $q): int => \count($q['params']), $dataQueries)));
    }

    #[Test]
    public function eachDialectResolvesToItsOwnReader(): void
    {
        self::assertInstanceOf(MysqlReferenceReader::class, ReferenceReaders::for(new MysqlDialect()));
        self::assertInstanceOf(PgsqlReferenceReader::class, ReferenceReaders::for(new PgsqlDialect()));
        self::assertInstanceOf(SqliteReferenceReader::class, ReferenceReaders::for(new SqliteDialect()));
    }

    #[Test]
    public function anUnknownDialectIsRefusedRatherThanGuessedAt(): void
    {
        // There is no sensible default: the catalogue is engine-specific, and picking one at random
        // would query a table the engine does not have.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('No ReferenceReader for dialect');
        ReferenceReaders::for($this->createStub(SqlDialect::class));
    }
}

/**
 * A DbSession that logs every query and returns canned rows in order.
 *
 * @internal
 */
final class ScriptedSession implements DbSession
{
    /** @var list<array{sql: string, params: array<array-key, scalar|\Nandan108\Attrecord\BinaryParam|null>}> */
    public array $queries = [];

    /** @var list<list<array<string, scalar|null>>> */
    private array $results;

    /** @param list<array<string, scalar|null>> ...$results one canned result set per fetchAll(), in order */
    public function __construct(array ...$results)
    {
        $this->results = array_values($results);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return array_shift($this->results) ?? [];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->fetchAll($sql, $params)[0] ?? null;
    }

    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        return null;
    }

    public function exec(string $sql, array $params = []): int
    {
        return 0;
    }

    public function lastInsertId(): string | int
    {
        return 0;
    }

    public function transactional(\Closure $operation): mixed
    {
        return $operation();
    }

    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $callback();
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return false;
    }

    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        return false;
    }
}
