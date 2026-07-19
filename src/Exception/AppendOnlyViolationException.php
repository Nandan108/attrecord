<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/**
 * Thrown when an update or delete is attempted on a Record that implements
 * {@see \Nandan108\Attrecord\AppendOnly} — those rows are write-once (insert only).
 *
 * @api
 */
final class AppendOnlyViolationException extends AttrecordException
{
    public static function forOperation(string $class, string $operation): self
    {
        return new self(sprintf(
            '%s is append-only (implements AppendOnly): %s is forbidden. Rows are write-once — '
            .'insert via RecordSet::insertAll() (bulk) or Record::save() on a new record; '
            .'never update or delete.',
            $class,
            $operation,
        ));
    }
}
