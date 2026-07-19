<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * Marker for Records whose rows are **write-once**: an append-only ledger, event log, outbox,
 * audit trail or journal. Reads are unrestricted; the **only** permitted write is an INSERT.
 *
 * attrecord enforces this at runtime on every write entry point (see the checks in
 * {@see Record::save()}, {@see Record::delete()}, {@see Record::updateWhere()},
 * {@see Record::updateByWhere()}, {@see Record::deleteWhere()}, {@see RecordSet::upsertAll()},
 * {@see RecordSet::deleteAll()}): any update or delete throws {@see Exception\AppendOnlyViolationException}.
 *
 * Inserting:
 *  - {@see RecordSet::insertAll()} — the sanctioned bulk-append path (one plain INSERT, no upsert).
 *  - {@see Record::save()} on a **new** record (PK still null / `isNew()`) — a single-row append.
 *
 * {@see RecordSet::upsertAll()} is rejected outright (not just when it would upsert): its insert-vs-upsert
 * behaviour is decided per record at runtime, so it cannot be a reliable append. Use `insertAll()`.
 *
 * @api
 */
interface AppendOnly
{
}
