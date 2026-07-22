<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Marks an integer column as the record's **optimistic-locking version**.
 *
 * Optimistic locking *detects* a concurrent write instead of *preventing* it. attrecord initialises
 * the column to `1` on INSERT; every UPDATE then adds `AND <version> = <the value you loaded>` to
 * the WHERE clause and sets `<version> = <version> + 1`. If another writer changed the row in the
 * meantime its version has moved on, no row matches, and
 * {@see \Nandan108\Attrecord\Exception\OptimisticLockException} is thrown rather than silently
 * overwriting their change (a lost update).
 *
 * This is the tool for conflicts that `SELECT … FOR UPDATE` cannot cover: a pessimistic lock only
 * holds *within one transaction*, so it is useless when the read and the write happen in **different
 * requests** (load a form, submit it minutes later). A version column is the only way to detect that.
 *
 * Because the UPDATE always increments the version, a matched row always genuinely changes — so
 * MySQL's changed-rows (rather than matched-rows) reporting cannot produce a false conflict.
 *
 * At most one `#[Version]` column per Record, and it must be an integer column.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Version
{
}
