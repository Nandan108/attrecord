<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/**
 * Referential action for FOREIGN KEY constraints (ON DELETE / ON UPDATE).
 *
 * @api
 */
enum ForeignKeyAction: string
{
    case Restrict = 'RESTRICT';
    case Cascade = 'CASCADE';
    case SetNull = 'SET NULL';
    case NoAction = 'NO ACTION';
    case SetDefault = 'SET DEFAULT';
}
