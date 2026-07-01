<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Session\WpDbSession;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Support/wpdb-double.php';

/**
 * Covers the WordPress `wpdb`-backed DbSession using a runtime `wpdb` double: placeholder
 * conversion (`?` → `%s`), NULL substitution, `%` escaping, the fetch/exec paths, transaction
 * nesting, advisory locks, and duplicate-key detection.
 */
final class WpDbSessionTest extends TestCase
{
    private \wpdb $wpdb;
    private WpDbSession $session;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->session = new WpDbSession($this->wpdb);
    }

    public function testExecSubstitutesNullLiteralsAndQuotesParams(): void
    {
        $this->wpdb->nextRowsAffected = 1;
        $affected = $this->session->exec(
            'INSERT INTO `t` (`a`, `b`) VALUES (?, ?)',
            ['x', null],
        );

        $this->assertSame(1, $affected);
        // The null param becomes a NULL literal; the non-null one is bound + quoted.
        $this->assertSame("INSERT INTO `t` (`a`, `b`) VALUES ('x', NULL)", $this->wpdb->lastSql());
    }

    public function testExecEscapesLiteralPercentAroundParams(): void
    {
        $this->session->exec("UPDATE `t` SET `s` = 'a%b' WHERE `id` = ?", [5]);

        // The literal % survives the %->%% escaping + wpdb's %%->% unescaping round-trip.
        $this->assertSame("UPDATE `t` SET `s` = 'a%b' WHERE `id` = '5'", $this->wpdb->lastSql());
    }

    public function testFetchAllOneScalar(): void
    {
        $this->wpdb->nextResults = [['id' => 1, 'name' => 'Alice']];
        $this->assertSame([['id' => 1, 'name' => 'Alice']], $this->session->fetchAll('SELECT * FROM `t` WHERE `id` = ?', [1]));

        $this->wpdb->nextRow = ['id' => 2, 'name' => 'Bob'];
        $this->assertSame(['id' => 2, 'name' => 'Bob'], $this->session->fetchOne('SELECT * FROM `t` LIMIT 1'));

        $this->wpdb->nextVar = 7;
        $this->assertSame(7, $this->session->fetchScalar('SELECT COUNT(*) FROM `t`'));
    }

    public function testLastInsertId(): void
    {
        $this->wpdb->insert_id = 99;
        $this->assertSame(99, $this->session->lastInsertId());
    }

    public function testTransactionalCommitNestsViaDepthCounter(): void
    {
        $this->assertFalse($this->session->inTransaction());

        $this->session->transactional(function (): void {
            $this->assertTrue($this->session->inTransaction());
            // Nested call must NOT issue a second START TRANSACTION.
            $this->session->transactional(function (): void {
                $this->assertTrue($this->session->inTransaction());
            });
        });

        $statements = array_map(static fn (array $c): string => $c[1], $this->wpdb->log);
        $this->assertSame(1, count(array_filter($statements, static fn ($s) => str_contains($s, 'START TRANSACTION'))));
        $this->assertSame(1, count(array_filter($statements, static fn ($s) => str_contains($s, 'COMMIT'))));
        $this->assertFalse($this->session->inTransaction());
    }

    public function testTransactionalRollsBackOnException(): void
    {
        try {
            $this->session->transactional(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $statements = array_map(static fn (array $c): string => $c[1], $this->wpdb->log);
        $this->assertNotEmpty(array_filter($statements, static fn ($s) => str_contains($s, 'ROLLBACK')));
        $this->assertFalse($this->session->inTransaction());
    }

    public function testWithAdvisoryLock(): void
    {
        $this->wpdb->nextVar = 1; // GET_LOCK returns 1 = acquired
        $result = $this->session->withAdvisoryLock('wp_lock', 5, static fn (): string => 'ran');

        $this->assertSame('ran', $result);
        $statements = array_map(static fn (array $c): string => $c[1], $this->wpdb->log);
        $this->assertNotEmpty(array_filter($statements, static fn ($s) => str_contains($s, 'GET_LOCK')));
        $this->assertNotEmpty(array_filter($statements, static fn ($s) => str_contains($s, 'RELEASE_LOCK')));
    }

    public function testWithAdvisoryLockThrowsWhenNotAcquired(): void
    {
        $this->wpdb->nextVar = 0; // not acquired
        $this->expectException(\RuntimeException::class);
        $this->session->withAdvisoryLock('wp_lock', 0, static fn (): string => 'never');
    }

    public function testIsDuplicateKeyError(): void
    {
        $dup = new \RuntimeException('Error: 1062 Duplicate entry for key');
        $this->assertTrue($this->session->isDuplicateKeyError($dup));

        $other = new \RuntimeException('some other error');
        $this->assertFalse($this->session->isDuplicateKeyError($other));
    }

    public function testCheckErrorThrowsOnWpdbError(): void
    {
        $this->wpdb->last_error = 'table missing';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wpdb: table missing');
        $this->session->exec('SELECT 1');
    }

    public function testIsRetryableTransactionError(): void
    {
        // From the throwable message …
        $this->assertTrue($this->session->isRetryableTransactionError(
            new \RuntimeException('wpdb: Deadlock found when trying to get lock; try restarting transaction'),
        ));

        // … or from wpdb::$last_error.
        $this->wpdb->last_error = 'Lock wait timeout exceeded; try restarting transaction';
        $this->assertTrue($this->session->isRetryableTransactionError(new \RuntimeException('generic')));

        $this->wpdb->last_error = '';
        $this->assertFalse($this->session->isRetryableTransactionError(new \RuntimeException('some other error')));
    }
}
