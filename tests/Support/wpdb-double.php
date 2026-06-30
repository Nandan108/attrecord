<?php

declare(strict_types=1);

// A minimal runtime double for the WordPress `wpdb` global, used by WpDbSessionTest.
// WordPress is not loaded in attrecord's test run, so `\wpdb` does not exist at runtime; this
// provides just the surface WpDbSession touches (prepare/query/get_*/insert_id/last_error),
// records the final SQL, and returns canned results. Psalm gets the real `wpdb` from the
// php-stubs stub file, so this file is excluded from Psalm analysis (see psalm.xml).

// WordPress output-format constants used by wpdb::get_results()/get_row().
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('wpdb', false)) {
    /** @psalm-suppress all */
    class wpdb // phpcs:ignore
    {
        public int | string $insert_id = 0;
        public int $rows_affected = 0;
        public string $last_error = '';

        /** @var list<array{0: string, 1: string}> recorded [method, finalSql] pairs */
        public array $log = [];

        /** @var list<array<string, scalar|null>> */
        public array $nextResults = [];
        public ?array $nextRow = null;
        public mixed $nextVar = null;
        public int $nextRowsAffected = 1;

        public function prepare(string $query, mixed ...$args): string
        {
            $i = 0;

            return (string) preg_replace_callback('/%[sdf%]/', function (array $m) use (&$i, $args): string {
                if ('%%' === $m[0]) {
                    return '%';
                }
                $v = $args[$i++] ?? null;
                if ('%d' === $m[0]) {
                    return (string) (int) $v;
                }
                if ('%f' === $m[0]) {
                    return (string) (float) $v;
                }

                return "'".$this->_real_escape((string) $v)."'";
            }, $query);
        }

        public function _real_escape(string $string): string
        {
            return addslashes($string);
        }

        public function query(string $sql): int | bool
        {
            $this->log[] = ['query', $sql];

            return $this->rows_affected = $this->nextRowsAffected;
        }

        public function get_results(string $sql, mixed $output = null): array
        {
            $this->log[] = ['get_results', $sql];

            return $this->nextResults;
        }

        public function get_row(string $sql, mixed $output = null): ?array
        {
            $this->log[] = ['get_row', $sql];

            return $this->nextRow;
        }

        public function get_var(string $sql): mixed
        {
            $this->log[] = ['get_var', $sql];

            return $this->nextVar;
        }

        public function lastSql(): ?string
        {
            $last = end($this->log);

            return false !== $last ? $last[1] : null;
        }
    }
}
