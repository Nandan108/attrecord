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
 *   $locks = LockSet::acquire($connection, [
 *       PurchaseOrder::class     => [$poId],
 *       PurchaseOrderLine::class => $lineIds,
 *       InventorySlot::class     => [$slotId],
 *   ], $tx);
 *
 * Acquisition order is determined by #[LockTier(n)] on each class (lowest tier first).
 * Within each table, rows are locked in ascending PK order. This eliminates the class of
 * deadlock caused by inconsistent lock acquisition order across concurrent transactions.
 *
 * Takes a {@see Connection} rather than a bare {@see DbSession} because it does not merely
 * execute SQL, it *generates* it: quoting the table and PK, and asking whether the backend has a
 * `FOR UPDATE` clause at all. A session cannot answer either question — a Connection is precisely
 * a session plus the dialect that can. Passing the connection the caller already holds is
 * therefore the whole contract; nothing here reads global state.
 *
 * @api
 */
final class LockSet
{
    /**
     * Acquire SELECT … FOR UPDATE locks in tier order.
     *
     * @param Connection                                    $connection session to read on + dialect to build with
     * @param array<class-string<Record>, list<int|string>> $targets    class → list of PKs to lock
     *
     * @return array<class-string<Record>, RecordSet> class → loaded+locked RecordSet
     *
     * @throws MissingLockTierException  if any target class lacks #[LockTier]
     * @throws LockTierConflictException if two target classes share the same tier
     */
    public static function acquire(
        Connection $connection,
        array $targets,
        ?Transaction $tx = null,
    ): array {
        // --- Validate tiers and sort ---
        $tiered = [];
        foreach ($targets as $class => $ids) {
            $schema = TableSchema::fromClass($class);
            // Ascending-PK ordering is the deadlock guarantee; on a composite key `pk` is only
            // the first member, so the ordering would be neither total nor the one other paths
            // use — two orderings of one table is precisely the deadlock this class prevents.
            $schema->assertSingleColumnPk('LockSet::acquire()');
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
        // One dialect for the whole set: every target is read on the one session passed in, so
        // they are by definition all on the same backend.
        $dialect = $connection->dialect;
        $session = $connection->session;

        $result = [];
        foreach ($tiered as [$class, $ids, $schema]) {
            if (empty($ids)) {
                $result[$class] = new RecordSet([]);
                continue;
            }

            // Quote identifiers so the FOR UPDATE read is portable (backticks on MySQL/MariaDB,
            // double quotes on PostgreSQL).
            $pk = $schema->pk;
            $qt = $dialect->quoteIdentifier($schema->tableName);
            $qpk = $dialect->quoteIdentifier($pk);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $forUpdateClause = $dialect->forUpdateClause();
            $sql = trim("SELECT * FROM {$qt} WHERE {$qpk} IN ({$placeholders}) ORDER BY {$qpk} ASC {$forUpdateClause}");

            // Bind the ids through the serializer so a binary PK is wrapped for the dialects
            // that need it (PostgreSQL bytea); int/string PKs and MySQL pass through unchanged.
            $pkColumn = $schema->columns[$pk];
            $bindBinaryAsLob = $dialect->bindsBinaryAsLob();
            $boundIds = array_map(
                static fn (int | string $id): mixed => ColumnSerializer::toParam($id, $pkColumn, $bindBinaryAsLob),
                $ids,
            );

            $rows = $session->fetchAll($sql, $boundIds);

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
