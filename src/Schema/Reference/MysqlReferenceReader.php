<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema\Reference;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Schema\AbstractReferenceReader;
use Nandan108\Attrecord\Schema\InboundReference;

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
    protected function readInbound(DbSession $session, string $table, ?string $column): array
    {
        $sql = 'SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.CONSTRAINT_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
                  FROM information_schema.KEY_COLUMN_USAGE kcu
                  JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                 WHERE kcu.TABLE_SCHEMA = DATABASE()
                   AND kcu.REFERENCED_TABLE_SCHEMA = DATABASE()
                   AND kcu.REFERENCED_TABLE_NAME = ?';
        $params = [$table];

        if (null !== $column) {
            $sql .= ' AND kcu.REFERENCED_COLUMN_NAME = ?';
            $params[] = $column;
        }

        $references = [];
        foreach ($session->fetchAll($sql, $params) as $row) {
            $references[] = new InboundReference(
                childTable: (string) $row['TABLE_NAME'],
                childColumn: (string) $row['COLUMN_NAME'],
                constraintName: (string) $row['CONSTRAINT_NAME'],
                referencedColumn: (string) $row['REFERENCED_COLUMN_NAME'],
                onDelete: self::action(isset($row['DELETE_RULE']) ? (string) $row['DELETE_RULE'] : null),
            );
        }

        return $references;
    }
}
