<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/** @api */
final class RecordSaveException extends AttrecordException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
