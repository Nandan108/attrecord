<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares this entity's position in the global lock acquisition order.
 *
 * Lower tier numbers are locked first. LockSet::acquire() sorts entities by tier before
 * issuing SELECT … FOR UPDATE, preventing deadlocks caused by inconsistent acquisition order.
 *
 * Rules:
 * - Two entities in the same LockSet must have different tiers (LockTierConflictException).
 * - An entity without #[LockTier] cannot participate in a LockSet (MissingLockTierException).
 * - Tier ordering should mirror the natural parent→child hierarchy of your schema.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class LockTier
{
    public function __construct(
        public readonly int $tier,
    ) {
    }
}
