<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/**
 * A versioned UPDATE matched no row: the record was changed (or deleted) by another writer since it
 * was loaded, so writing would have silently overwritten that change.
 *
 * @see \Nandan108\Attrecord\Attribute\Version
 *
 * @api
 */
final class OptimisticLockException extends AttrecordException
{
    public function __construct(
        /** @var class-string */
        public readonly string $recordClass,
        public readonly int | string $id,
        public readonly int $expectedVersion,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                '%s#%s was modified by another writer (expected version %d). Reload the record and reapply the change.',
                $recordClass,
                (string) $id,
                $expectedVersion,
            ),
            0,
            $previous,
        );
    }
}
