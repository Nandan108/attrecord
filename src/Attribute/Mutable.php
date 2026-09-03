<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Marks one column of an {@see \Nandan108\Attrecord\Immutable} (or
 * {@see \Nandan108\Attrecord\AppendOnly}) Record as still writable after insert.
 *
 *     #[Column(ColumnType::DateTime, nullable: true)]
 *     #[Mutable]
 *     public ?\DateTimeImmutable $invalid_at = null;
 *
 * **Declared at the field, deliberately.** A class-level list of exceptions sits far from the
 * columns it exempts and rots as they change; here a reader of `invalid_at` sees that it moves, and
 * a reader of any other column sees no such marker and can still trust the row's promise. That
 * placement is what keeps the row-level marker worth having: the guarantee is narrowed in exactly
 * one visible place rather than hollowed out from a distance.
 *
 * ## When a column belongs here
 *
 * The test is whether the column is part of **what the row is**, or metadata **about** it.
 *
 * The case this was built for is the content-addressed row, where the answer is written down
 * already: the primary key is a digest of the identity-bearing columns, so those cannot change
 * without breaking the row's own identity — but a column outside the digest was never part of that
 * identity. A validity flag ("these contact details are dead") is a fact *about* the interned facts,
 * true for every document that ever stated them, and marking it does not touch what the key means.
 *
 * The other canonical case is an append-only outbox with a `dispatched_at`: the row records that an
 * event happened, and that record never changes; whether it has been sent yet is bookkeeping laid
 * over it.
 *
 * A column that would change *what the row asserts* does not belong here — and on a content-
 * addressed table the engine will tell you, because editing a digested column leaves the row under a
 * key that no longer describes it.
 *
 * ## What it does not relax
 *
 * Only the update guards consult it. `RecordSet::upsertAll()` and `upsertAllByUniqueKey()` stay
 * refused on an `Immutable` Record however many columns are marked, because whether either inserts
 * or updates is decided per record at runtime — so neither can be relied on to touch only the
 * columns you exempted. Deletes are unaffected: they remain `AppendOnly`'s question.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Mutable
{
}
