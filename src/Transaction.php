<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Exception\LockAssertionException;

/**
 * Tracks which records have been locked (SELECT … FOR UPDATE) within one transactional() call.
 *
 * Passed to the closure by Record::transactional(). Use assertLocked() as a development-mode
 * guard to verify that writes happen only on properly locked records.
 *
 * assertLocked() is a no-op when ATTRECORD_LOCK_ASSERTIONS is false (production default).
 */
final class Transaction
{
    /** @var array<class-string, list<int|string>> entity class → list of locked PKs */
    private array $lockedRecords = [];

    /** @var list<self> */
    private static array $stack = [];

    private function __construct()
    {
    }

    /**
     * Return the Transaction currently on the stack, or null if not inside transactional().
     *
     * @api
     */
    public static function current(): ?self
    {
        $count = count(self::$stack);

        return $count > 0 ? self::$stack[$count - 1] : null;
    }

    /** @internal Called by Record::transactional(). */
    public static function push(): self
    {
        $tx = new self();
        self::$stack[] = $tx;

        return $tx;
    }

    /** @internal Called by Record::transactional(). */
    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    /**
     * Register that the given record has been locked in this transaction.
     *
     * @internal called by Record finders when forUpdate: true
     */
    public function registerLock(Record $record): void
    {
        $class = $record::class;
        $schema = $record::schema();
        /** @psalm-suppress MixedAssignment */
        $id = $record->{$schema->primaryKey};
        /** @psalm-suppress MixedPropertyTypeCoercion */
        $this->lockedRecords[$class][] = $id;
    }

    /**
     * Assert that the given record was fetched with forUpdate: true in this transaction.
     *
     * This is a development guard — it is a no-op unless ATTRECORD_LOCK_ASSERTIONS is true.
     *
     * @api
     *
     * @throws LockAssertionException
     */
    public function assertLocked(Record $record): void
    {
        if (!defined('ATTRECORD_LOCK_ASSERTIONS') || !ATTRECORD_LOCK_ASSERTIONS) {
            return;
        }

        $class = $record::class;
        $schema = $record::schema();
        /** @psalm-suppress MixedAssignment */
        $id = $record->{$schema->primaryKey};

        if (!in_array($id, $this->lockedRecords[$class] ?? [], strict: true)) {
            /** @psalm-suppress MixedArgument */
            throw new LockAssertionException($class, $id);
        }
    }
}
