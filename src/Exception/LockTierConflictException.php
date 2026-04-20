<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/** @api */
final class LockTierConflictException extends AttrecordException
{
    /**
     * @param class-string $classA
     * @param class-string $classB
     */
    public function __construct(string $classA, string $classB, int $tier)
    {
        parent::__construct(
            sprintf(
                'LockSet tier conflict: %s and %s both declare #[LockTier(%d)]. Each entity in a LockSet must have a unique tier.',
                $classA,
                $classB,
                $tier,
            ),
        );
    }
}
