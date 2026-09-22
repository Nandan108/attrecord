<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema\Reference;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Schema\AbstractReferenceReader;

/**
 * Inbound foreign keys on MySQL and MariaDB, from `information_schema`.
 *
 * `KEY_COLUMN_USAGE` carries the columns of every constraint and, for a foreign key, the table and
 * column it references — so the inbound question is the same row the outbound one reads, filtered on
 * `REFERENCED_TABLE_NAME` instead of `TABLE_NAME`. `REFERENTIAL_CONSTRAINTS` is joined for the
 * `DELETE_RULE`, which lives nowhere else.
 *
 * Scoped to `DATABASE()` on both sides of the join: a same-named table in another schema on the
 * same server would otherwise contribute rows that have nothing to do with this install.
 */
final class MysqlReferenceReader extends AbstractReferenceReader
{
    #[\Override]
    protected function readInbound(DbSession $session, string $table): array
    {
        $sql = 'SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.CONSTRAINT_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
                  FROM information_schema.KEY_COLUMN_USAGE kcu
                  JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                 WHERE kcu.TABLE_SCHEMA = DATABASE()
                   AND kcu.REFERENCED_TABLE_SCHEMA = DATABASE()
                   AND kcu.REFERENCED_TABLE_NAME = ?
                 ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION';

        // ORDINAL_POSITION is the column's place *within its constraint*, so ordering by it keeps a
        // multi-column key's pairs in declaration order — which is what makes them a key.
        $rows = [];
        foreach ($session->fetchAll($sql, [$table]) as $row) {
            $rows[] = [
                'table'      => (string) $row['TABLE_NAME'],
                'constraint' => (string) $row['CONSTRAINT_NAME'],
                'child'      => (string) $row['COLUMN_NAME'],
                'referenced' => (string) $row['REFERENCED_COLUMN_NAME'],
                'onDelete'   => isset($row['DELETE_RULE']) ? (string) $row['DELETE_RULE'] : null,
            ];
        }

        return self::assemble($rows);
    }
}
