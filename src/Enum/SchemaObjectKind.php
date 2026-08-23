<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/**
 * The kinds of schema object a table is made of, as a value that can be passed around and switched
 * on — used where a name alone is ambiguous, since an index and a constraint may legitimately share
 * one on engines that scope them separately.
 *
 * @api
 */
enum SchemaObjectKind: string
{
    case Index = 'index';
    case UniqueKey = 'unique_key';
    case ForeignKey = 'foreign_key';
    case Check = 'check';
    case Column = 'column';

    /** Human wording for a plan or an error message ("unique key", not "unique_key"). */
    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
