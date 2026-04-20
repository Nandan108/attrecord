<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * Counts returned by RecordSet::saveAll().
 *
 * `inserted`    — rows written for the first time (plain INSERT for new records,
 *                 plus INSERT IGNORE / ON CONFLICT DO NOTHING for keyed records
 *                 that did not yet exist in the table).
 * `updated`     — existing rows whose columns were overwritten by the CASE UPDATE step.
 * `insertedIds` — auto-generated PKs for new (no-key) records inserted by saveAll().
 *                 Populated via RETURNING on PostgreSQL, or via lastInsertId() + sequential
 *                 range on MySQL/MariaDB. Empty for keyed records (their PKs were already set).
 *
 * **Limitation (MySQL/MariaDB):** the sequential-range assumption holds for single-node
 * InnoDB with the default `innodb_autoinc_lock_mode`. On clustered/Galera setups the
 * auto-increment sequence may have gaps; in that case prefer individual `Record::save()`
 * calls when you need the assigned PKs.
 *
 * @api
 */
final class SaveResult
{
    /**
     * @param list<int|string> $insertedIds
     */
    public function __construct(
        public readonly int $inserted,
        public readonly int $updated,
        public readonly array $insertedIds = [],
    ) {
    }

    /** Total rows written (inserted + updated). */
    public function total(): int
    {
        return $this->inserted + $this->updated;
    }
}
