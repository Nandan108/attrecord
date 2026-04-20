<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/** @api */
final class LockAssertionException extends AttrecordException
{
    public function __construct(string $class, int | string $id)
    {
        parent::__construct(
            sprintf(
                'Transaction::assertLocked() failed: %s(%s) was not fetched with forUpdate: true in this transaction.',
                $class,
                $id,
            ),
        );
    }
}
