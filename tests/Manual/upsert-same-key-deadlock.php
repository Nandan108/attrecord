<?php

declare(strict_types=1);

/**
 * Reproduces the same-key lock-conversion deadlock that {@see Nandan108\Attrecord\Enum\UpsertStrategy}
 * documents, and shows that `Lockless` survives the identical interleaving.
 *
 * NOT a PHPUnit test, deliberately, and not only because it needs two connections:
 *
 *   **A test should assert the remedy, not the defect.** "`Locked` deadlocks on a contended single
 *   key" is a known limitation, not a requirement — asserting it would mean that fixing it one day
 *   makes the suite go red, which is the wrong way round. The half worth asserting is that
 *   `Lockless` does *not* deadlock there, and that belongs in the integration suite if the harness
 *   ever grows a second connection. Until then this script is the evidence, runnable on demand.
 *
 * Why two processes: the deadlock needs both sessions holding their shared lock *before* either asks
 * for the exclusive one. A single-threaded script cannot do that — the first `FOR UPDATE` blocks, so
 * the second session never issues its statement and you get a lock-wait timeout (1205) rather than a
 * deadlock (1213). The two workers below rendezvous on a flag file so both S locks are held first;
 * after that the cycle is immediate and InnoDB's detector fires rather than anything timing out.
 *
 * Usage (MySQL/MariaDB only — the conversion is an InnoDB behaviour):
 *
 *     php tests/Manual/upsert-same-key-deadlock.php            # defaults to 127.0.0.1:3306
 *     DB_PORT=3399 php tests/Manual/upsert-same-key-deadlock.php
 *
 * Measured 2026-10-20 — `Locked` deadlocks and `Lockless` does not, on MariaDB 10.11.14,
 * MySQL 8.4.10 and MariaDB 11.8.6. Which of the two sessions is chosen as the victim varies by
 * engine and is not part of the claim.
 */
const TABLE = 'dl_probe_same_key';

/** Read an env var, falling back when it is unset *or* empty — `?:` on `false|string` is psalm-risky. */
$env = static function (string $key, string $default): string {
    $raw = getenv($key);

    return false === $raw || '' === $raw ? $default : $raw;
};

$port = $env('DB_PORT', '3306');
$host = $env('DB_HOST', '127.0.0.1');
$name = $env('DB_NAME', 'attrecord_test');
$user = $env('DB_USER', 'root');
// Password may legitimately be empty, so only "unset" falls back.
$pass = false === getenv('DB_PASS') ? 'root' : (string) getenv('DB_PASS');

$connect = static function () use ($host, $port, $name, $user, $pass): PDO {
    return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
};

// ---------------------------------------------------------------- worker role
if (isset($argv[1], $argv[2], $argv[3]) && '--worker' === $argv[1]) {
    [, , $me, $mode] = $argv;
    $dir = $argv[4];
    $other = 'A' === $me ? 'B' : 'A';

    $pdo = $connect();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

    $rendezvous = static function (string $mine, string $theirs) use ($dir): void {
        touch("{$dir}/{$mine}");
        // Bounded, and it RETURNS on success: a `while (!file_exists(...))` whose only exit is a
        // throw reads to psalm as `never`, which makes everything after the call unevaluated.
        for ($i = 0; $i < 5000; ++$i) {
            if (file_exists("{$dir}/{$theirs}")) {
                return;
            }
            usleep(2000);
        }

        throw new RuntimeException('rendezvous timed out — the other worker never arrived');
    };

    try {
        $pdo->exec('BEGIN');
        if ('locked' === $mode) {
            // Step 1 of the Locked three-step. Against a row that already exists this leaves a
            // SHARED lock on it; `ON DUPLICATE KEY UPDATE` would have taken an exclusive one.
            $pdo->exec('INSERT IGNORE INTO '.TABLE.' (id, v) VALUES (1, 1)');
            $rendezvous("s-{$me}", "s-{$other}");
            // Step 2. Both sessions now hold S and both ask for X on that one row.
            $pdo->query('SELECT id FROM '.TABLE.' WHERE id IN (1) ORDER BY id ASC FOR UPDATE')->fetchAll();
        } else {
            $rendezvous("s-{$me}", "s-{$other}");
            $pdo->exec('INSERT INTO '.TABLE.' (id, v) VALUES (1, 1) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        }
        $pdo->exec('COMMIT');
        file_put_contents("{$dir}/out-{$me}", 'committed');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // already rolled back by the engine
        }
        file_put_contents("{$dir}/out-{$me}", $e->getCode().': '.$e->getMessage());
    }

    exit(0);
}

// ----------------------------------------------------------- coordinator role
$pdo = $connect();
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
printf("server: %s  (%s:%s/%s)\n\n", $version, $host, $port, $name);

foreach (['locked', 'lockless'] as $mode) {
    $pdo->exec('DROP TABLE IF EXISTS '.TABLE);
    $pdo->exec('CREATE TABLE '.TABLE.' (id INT UNSIGNED PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO '.TABLE.' (id, v) VALUES (1, 0)');   // the row both sessions will upsert

    $dir = sys_get_temp_dir().'/dlprobe-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $procs = [];
    foreach (['A', 'B'] as $who) {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__)
            .' --worker '.$who.' '.$mode.' '.escapeshellarg($dir);
        $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    }
    foreach ($procs as $p) {
        if (is_resource($p)) {
            proc_close($p);
        }
    }

    printf("%s\n", strtoupper($mode));
    $deadlocks = 0;
    foreach (['A', 'B'] as $who) {
        $raw = @file_get_contents("{$dir}/out-{$who}");
        $out = false === $raw || '' === $raw ? '(no result — worker died)' : $raw;
        if (str_contains($out, '1213')) {
            ++$deadlocks;
        }
        printf("  %s: %s\n", $who, substr($out, 0, 110));
    }
    printf(
        "  => %s\n\n",
        'locked' === $mode
            ? ($deadlocks > 0 ? "DEADLOCKED as documented ({$deadlocks} victim)" : 'NO deadlock — the documented claim did not reproduce here')
            : (0 === $deadlocks ? 'no deadlock — the remedy holds' : 'DEADLOCKED — the remedy did NOT hold, which is a regression'),
    );

    $leftovers = glob("{$dir}/*");
    foreach (false === $leftovers ? [] : $leftovers as $leftover) {
        unlink($leftover);
    }
    rmdir($dir);
}

$pdo->exec('DROP TABLE IF EXISTS '.TABLE);
