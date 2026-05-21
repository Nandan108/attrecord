<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/**
 * Thrown by {@see \Nandan108\Attrecord\Record::validate()} when a record's
 * current property values violate its domain invariants.
 *
 * Subclasses of `Record` opt into validation by overriding `validate()`. The base
 * implementation is a no-op, so records without invariants do nothing. When invariants
 * are violated, throw this exception (or a subclass) with a human-readable message
 * and optional context.
 *
 * Validation runs:
 *   - At the end of {@see \Nandan108\Attrecord\Record::set()} when `$validate` is true
 *     (the default) — catches invalid state at the point of mass assignment.
 *   - Inside {@see \Nandan108\Attrecord\Record::save()} just after `beforeSave()` —
 *     belt-and-braces guarantee that no invalid row ever reaches the database, even
 *     if a caller bypassed `set()` and assigned properties directly.
 *   - Inside {@see \Nandan108\Attrecord\RecordSet::saveAll()} in the same loop as
 *     `beforeSave()` for the same reason.
 *
 * @api
 */
class RecordValidationException extends AttrecordException
{
    /**
     * @param string               $message  Human-readable description of the invariant violation
     * @param array<string, mixed> $context  Optional extra context (offending field name, value, etc.)
     * @param ?\Throwable          $previous Previous exception, if chaining
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
