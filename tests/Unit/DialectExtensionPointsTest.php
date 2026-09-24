<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\BinaryPkRecord;
use PHPUnit\Framework\TestCase;

/**
 * The dialects are subclassable, and **only their capability answers are**.
 *
 * A backend can speak MySQL's SQL and behave differently — a translator, a proxy, a compatibility
 * layer. Rather than grow a flag per quirk, or teach attrecord the name of each such environment,
 * the dialect classes are open for a consumer to correct the handful of answers that describe what
 * the backend *can do*. What SQL those answers select between stays closed, because it is what the
 * tri-engine matrix covers.
 *
 * The reflection case below is the load-bearing one. The split is a contract, and a contract only
 * stated in a docblock drifts the first time somebody adds a method: a new `final` capability
 * predicate is unoverridable in the field with no test going red, and a non-final builder is an
 * untested branch presenting itself as this dialect.
 */
final class DialectExtensionPointsTest extends TestCase
{
    /** The four answers that describe the backend rather than the SQL. */
    private const EXTENSION_POINTS = [
        'bindsBinaryAsLob',
        'supportsReturning',
        'forUpdateClause',
        'connectionInitStatements',
    ];

    /** @return list<array{0: class-string}> */
    public static function dialects(): array
    {
        return [[MysqlDialect::class], [PgsqlDialect::class], [SqliteDialect::class]];
    }

    /**
     * @param class-string $class
     *
     * @dataProvider dialects
     */
    public function testTheDialectCanBeSubclassed(string $class): void
    {
        self::assertFalse(
            (new \ReflectionClass($class))->isFinal(),
            "$class must stay open for a consumer whose backend answers differently",
        );
    }

    /**
     * @param class-string $class
     *
     * @dataProvider dialects
     */
    public function testExactlyTheCapabilityAnswersAreOverridable(string $class): void
    {
        $refl = new \ReflectionClass($class);

        $overridable = [];
        foreach ($refl->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isFinal() || $method->isStatic() || $method->isConstructor()) {
                continue;
            }
            // Declared here, not inherited from a parent this class does not have.
            if ($method->getDeclaringClass()->getName() === $class) {
                $overridable[] = $method->getName();
            }
        }

        sort($overridable);
        $expected = self::EXTENSION_POINTS;
        sort($expected);

        self::assertSame($expected, $overridable, sprintf(
            '%s: every public method except the capability answers must be final. '
            .'A non-final builder is an untested branch wearing this dialect\'s name.',
            $class,
        ));
    }

    /**
     * The point of the seam, end to end: a subclass says the backend needs binary values marked,
     * and attrecord binds a `BinaryParam` instead of a bare string — without the subclass touching
     * any SQL.
     *
     * This is the WordPress-SQLite-translator case. It reports MySQL 8.0, so `MysqlDialect` is
     * right about every statement it builds and wrong about exactly this: the driver quotes a
     * string parameter as text, and SQLite never compares a BLOB equal to a TEXT literal, so every
     * lookup by a binary id matches nothing. Silently — the rows are simply absent.
     */
    public function testAnOverriddenCapabilityAnswerReachesTheBoundParameters(): void
    {
        $id = hex2bin('0192f8a4b3c27d4e8f1a2b3c4d5e6f70');
        self::assertIsString($id);

        $stock = $this->captureKeyLookupParam(new MysqlDialect(), $id);
        self::assertIsString($stock, 'stock MysqlDialect binds a binary key as a plain string');

        $subclassed = $this->captureKeyLookupParam(new TranslatorBackedMysqlDialect(), $id);
        self::assertInstanceOf(BinaryParam::class, $subclassed, 'the override is honoured');
        self::assertSame($id, $subclassed->bytes, 'and it carries the bytes unchanged');
    }

    /** The subclass changes the binding, never the SQL. */
    public function testTheSubclassEmitsIdenticalSql(): void
    {
        $schema = TableSchema::fromClass(BinaryPkRecord::class);

        self::assertSame(
            (new MysqlDialect())->buildCreateTable($schema),
            (new TranslatorBackedMysqlDialect())->buildCreateTable($schema),
        );
    }

    /**
     * Run a by-key read and hand back the single bound parameter, **as the session received it**.
     *
     * Deliberately not `CapturingDbSession::lastParams()`: that double unwraps a `BinaryParam` to
     * its bytes so ordinary assertions can compare values, which is the right call for every other
     * test and fatal for this one. Reading through it, a wrapped and an unwrapped parameter are
     * indistinguishable — so this test would pass unchanged if the override did nothing at all.
     */
    private function captureKeyLookupParam(MysqlDialect $dialect, string $id): mixed
    {
        $session = new RawParamCapturingSession();
        Record::setConnection(new Connection($session, $dialect));
        TableSchema::clearCache();

        BinaryPkRecord::getOne($id);

        self::assertCount(1, $session->rawParams, 'one key, one bound parameter');

        return $session->rawParams[0];
    }
}

/**
 * @internal records bound parameters verbatim, wrapper and all
 *
 * Written rather than reused: {@see CapturingDbSession} unwraps a `BinaryParam` on the way in, so
 * the one difference under test is invisible through it
 */
final class RawParamCapturingSession implements DbSession
{
    /** @var list<mixed> */
    public array $rawParams = [];

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $this->rawParams = array_values($params);

        return 0;
    }

    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->rawParams = array_values($params);

        return [];
    }

    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->rawParams = array_values($params);

        return null;
    }

    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        $this->rawParams = array_values($params);

        return null;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return 0;
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        return $operation();
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $callback();
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return false;
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return false;
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        return false;
    }
}

/** @internal a consumer's dialect for a backend that speaks MySQL but stores in SQLite */
final class TranslatorBackedMysqlDialect extends MysqlDialect
{
    #[\Override]
    public function bindsBinaryAsLob(): bool
    {
        return true;
    }
}
