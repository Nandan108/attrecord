<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Exception\LockTierConflictException;
use Nandan108\Attrecord\Exception\MissingLockTierException;
use Nandan108\Attrecord\Schema\TableSchema;

/**
 * Acquires row locks on multiple entity classes in a deterministic tier-based order.
 *
 * Usage:
 *   $locks = LockSet::acquire($session, [
 *       PurchaseOrder::class     => [$poId],
 *       PurchaseOrderLine::class => $lineIds,
 *       InventorySlot::class     => [$slotId],
 *   ], $tx);
 *
 * Acquisition order is determined by #[LockTier(n)] on each class (lowest tier first).
 * Within each table, rows are locked in ascending PK order. This eliminates the class of
 * deadlock caused by inconsistent lock acquisition order across concurrent transactions.
 *
 * @api
 */
final class LockSet
{
    /**
     * Acquire SELECT … FOR UPDATE locks in tier order.
     *
     * @param array<class-string<Record>, list<int|string>> $targets class → list of PKs to lock
     *
     * @return array<class-string<Record>, RecordSet> class → loaded+locked RecordSet
     *
     * @throws MissingLockTierException  if any target class lacks #[LockTier]
     * @throws LockTierConflictException if two target classes share the same tier
     */
    public static function acquire(
        DbSession $session,
        array $targets,
        ?Transaction $tx = null,
    ): array {
        // --- Validate tiers and sort ---
        $tiered = [];
        foreach ($targets as $class => $ids) {
            $schema = TableSchema::fromClass($class);
            if (null === $schema->lockTier) {
                throw new MissingLockTierException($class);
            }
            $tier = $schema->lockTier;
            if (isset($tiered[$tier])) {
                throw new LockTierConflictException($tiered[$tier][0], $class, $tier);
            }
            $tiered[$tier] = [$class, $ids, $schema];
        }
        ksort($tiered); // ascending tier → correct acquisition order

        // --- Acquire locks in tier order ---
        $result = [];
        foreach ($tiered as [$class, $ids, $schema]) {
            if (empty($ids)) {
                $result[$class] = new RecordSet([]);
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $pk = $schema->pk;
            $table = $schema->tableName;
            $sql = "SELECT * FROM `{$table}` WHERE `{$pk}` IN ({$placeholders}) ORDER BY `{$pk}` ASC FOR UPDATE";

            $rows = $session->fetchAll($sql, $ids);

            $records = [];
            foreach ($rows as $row) {
                /** @var class-string<Record> $class */
                /** @psalm-suppress UnsafeInstantiation */
                $record = new $class();
                $record->hydrateFromRow($row);
                if (null !== $tx) {
                    $tx->registerLock($record);
                }
                $records[] = $record;
            }

            $result[$class] = new RecordSet($records);
        }

        return $result;
    }
}
