<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/** @api */
final class MissingLockTierException extends AttrecordException
{
    /** @param class-string $class */
    public function __construct(string $class)
    {
        parent::__construct(
            sprintf(
                '%s does not declare #[LockTier(n)]. Add the attribute before using this class in a LockSet.',
                $class,
            ),
        );
    }
}
