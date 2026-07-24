<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/**
 * Conflict policy for an INSERT (see {@see \Nandan108\Attrecord\RecordSet::insertAll()} and
 * {@see \Nandan108\Attrecord\Record::save()}).
 *
 * `Fail` — the default and prior behaviour: a row that collides on the primary key or a unique key
 * raises a DB error (wrapped in RecordSaveException). The correct choice for append-only writes,
 * where a collision is a bug to surface loudly.
 *
 * `Ignore` — a colliding row is silently skipped; every non-conflicting row still inserts. Only
 * **key** conflicts are absorbed: a NOT NULL / CHECK / truncation violation still surfaces, because
 * attrecord emits `ON DUPLICATE KEY UPDATE <col> = <col>` (MySQL/MariaDB) or `ON CONFLICT DO NOTHING`
 * (PostgreSQL/SQLite) — never the blunt `INSERT IGNORE` / `INSERT OR IGNORE`, which would also
 * swallow those errors. Intended for idempotent seeds and fire-and-forget batches.
 *
 * Back-fill caveat: a skipped row receives no DB-generated id, so on an auto-increment table the PK
 * is **not** back-filled onto the records under `Ignore` (a mixed insert/skip batch cannot be
 * aligned position-for-position). Use `Ignore` with client-minted PKs, or when back-fill isn't
 * needed. `SaveResult::$inserted` still reports the count of rows the DB actually inserted.
 *
 * @api
 */
enum OnConflict
{
    case Fail;
    case Ignore;
}
