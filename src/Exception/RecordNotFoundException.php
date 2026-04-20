<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Exception;

/** @api */
final class RecordNotFoundException extends AttrecordException
{
    /** @param class-string $class */
    public function __construct(string $class, int | string $id)
    {
        parent::__construct(sprintf('%s with id %s not found.', $class, $id));
    }
}
