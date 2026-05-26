<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/**
 * Storage mode for SQL generated columns.
 *
 * - `Stored`: the value is materialized at write time (takes disk space, can be indexed
 *   without restriction).
 * - `Virtual`: the value is recomputed on every read (no extra storage, indexable in
 *   MySQL 8+ but with caveats).
 *
 * MySQL spelling: `GENERATED ALWAYS AS (expr) STORED | VIRTUAL`.
 *
 * @api
 */
enum GeneratedColumnMode: string
{
    case Stored = 'STORED';
    case Virtual = 'VIRTUAL';
}
