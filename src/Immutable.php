<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * Marker for Records whose rows **never change once written**. Reads are unrestricted, INSERT is
 * permitted, and DELETE is permitted — the row may cease to exist, but for as long as it exists it
 * says exactly what it said when it was written.
 *
 * attrecord enforces this at runtime on every *update* entry point ({@see Record::save()} on an
 * existing row, {@see Record::updateWhere()}, {@see Record::updateByWhere()},
 * {@see RecordSet::upsertAll()}, {@see RecordSet::upsertAllByUniqueKey()}): each throws
 * {@see Exception\AppendOnlyViolationException}.
 *
 * {@see RecordSet::upsertAll()} and `upsertAllByUniqueKey()` are rejected outright rather than only
 * when they would actually update: the insert-vs-update choice is made per record at runtime, so
 * neither can be relied on to insert.
 *
 * ## Against {@see AppendOnly}
 *
 * `AppendOnly` **extends this interface** and adds one restriction: the row may not be deleted
 * either. Choose between them by asking what the table promises:
 *
 * - **`Immutable`** — the *content* is fixed, the *existence* is not. The case that motivates it is
 *   a **content-addressed** row: one whose key is a digest of its own fields, interned and shared by
 *   everything that states the same facts. An "edit" there is incoherent — it would break the row's
 *   own identity, and silently for every other holder of the key. But removing one nothing points at
 *   loses nothing either, because re-interning the same facts recomputes the *same* key. Reaping an
 *   orphan is not a deletion in the usual sense; it is dropping a cache entry that can be rebuilt.
 * - **`AppendOnly`** — an event log, ledger, outbox, audit trail. There the *existence* of a row is
 *   itself the assertion ("this happened"), so removing one rewrites history exactly as editing one
 *   would.
 *
 * The distinction is not about how much is protected, but about **what the row is claiming**: a
 * ledger row asserts that an event occurred, while a content-addressed row asserts only that these
 * facts go by this key — which stays true whether or not the row is stored anywhere.
 *
 * ## A content-addressed row has no identity to lose
 *
 * That last sentence is worth stating on its own, because it is the property several operations
 * quietly depend on rather than a fact about deletion. When the key is derived from the content, the
 * identity is **recomputable from the facts** — so nothing that removes or re-creates the row can
 * destroy the identity, only the storage of it. `deleteUnreferenced()` is one consequence; so is
 * re-interning a row after a merge, and so is re-deriving a key after a restore. Each of those is a
 * union or a rebuild rather than a rewrite.
 *
 * Which sets the boundary on where the same operations are merely *available* rather than
 * well-behaved. With a sequential key, a deleted row is gone: the number meant nothing beyond
 * pointing at that row, so it cannot be recomputed, and re-inserting the same facts produces a
 * different identity that every stale reference now disagrees with. Both tables may be `Immutable`
 * and both may reap orphans, but only one of them can lose the row and keep the identity.
 *
 * @api
 */
interface Immutable
{
}
