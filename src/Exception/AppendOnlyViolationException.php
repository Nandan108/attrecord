<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Immutable;

/**
 * Thrown when a forbidden write is attempted on a Record marked
 * {@see Immutable} (no updates) or {@see AppendOnly}
 * (no updates and no deletes).
 *
 * The message names **which** marker the class actually carries, because the two permit different
 * things and a reader hitting this is deciding what to do instead: telling the author of an
 * `Immutable` Record "never update or delete" would send them away from `delete()`, which is
 * available to them and is very likely what they want.
 *
 * @api
 */
final class AppendOnlyViolationException extends AttrecordException
{
    /**
     * @param class-string|string $class     the Record class the operation was attempted on
     * @param string              $operation the forbidden operation, as the caller would have written it
     */
    public static function forOperation(string $class, string $operation): self
    {
        // Read the marker off the class rather than taking it from the call site: every guard would
        // otherwise have to pass it, and the one that forgot would produce a confidently wrong
        // message with nothing to catch it.
        $appendOnly = is_a($class, AppendOnly::class, true);

        return new self(sprintf(
            '%s is %s (implements %s): %s is forbidden. %s',
            $class,
            $appendOnly ? 'append-only' : 'immutable',
            $appendOnly ? 'AppendOnly' : 'Immutable',
            $operation,
            $appendOnly
                ? 'Rows are write-once — insert via RecordSet::insertAll() (bulk) or Record::save() '
                    .'on a new record; never update or delete.'
                : 'Row content never changes — insert via RecordSet::insertAll() (bulk) or '
                    .'Record::save() on a new record. Deleting IS permitted; only updates are not.',
        ));
    }

    /**
     * A write refused because it touched a column the Record has **not** exempted with
     * `#[Mutable]` — the case where some columns do move, so naming the offender is the whole
     * message. Told without it, the author of a Record with three exempted columns learns only
     * that "an update" was refused and has to diff the two lists by hand.
     *
     * @param class-string|string $class
     */
    public static function forColumn(string $class, string $operation, string $column): self
    {
        return new self(sprintf(
            '%s is %s: %s would write "%s", which is not marked #[Mutable]. Row content is fixed '
            .'except for the columns that carry that attribute; if this one should move, declare it '
            .'there — and if it is part of what identifies the row, it should not.',
            $class,
            is_a($class, AppendOnly::class, true) ? 'append-only' : 'immutable',
            $operation,
            $column,
        ));
    }
}
