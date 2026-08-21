<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema\Reference;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Schema\AbstractReferenceReader;
use Nandan108\Attrecord\Schema\InboundReference;

/**
 * Inbound foreign keys on PostgreSQL, from `pg_constraint` rather than `information_schema`.
 *
 * The standard views can answer this, but only by joining four of them, and
 * `constraint_column_usage` is notoriously permission-filtered — it shows a constraint only to
 * someone with rights on the referenced table. `pg_constraint` answers it in one join: `confrelid`
 * *is* the referenced table, which makes the inbound direction a plain equality rather than a
 * derived fact.
 *
 * `conkey` and `confkey` are parallel arrays — the child columns and the parent columns they pair
 * with — so unnesting them together keeps a composite key's pairs correctly matched instead of
 * producing their cross product.
 *
 * Scoping is free here: `to_regclass()` resolves an unqualified name through `search_path`, so the
 * answer is about the schema this connection is actually using, and an unknown table yields NULL,
 * which matches nothing.
 */
final class PgsqlReferenceReader extends AbstractReferenceReader
{
    /** `pg_constraint.confdeltype` — a single character per referential action. */
    private const DELETE_ACTIONS = [
        'a' => ForeignKeyAction::NoAction,
        'r' => ForeignKeyAction::Restrict,
        'c' => ForeignKeyAction::Cascade,
        'n' => ForeignKeyAction::SetNull,
        'd' => ForeignKeyAction::SetDefault,
    ];

    #[\Override]
    protected function readInbound(DbSession $session, string $table, ?string $column): array
    {
        $sql = 'SELECT c.conrelid::regclass::text AS child_table,
                       a.attname                  AS child_column,
                       c.conname                  AS constraint_name,
                       ra.attname                 AS referenced_column,
                       c.confdeltype              AS delete_rule
                  FROM pg_constraint c
                  JOIN LATERAL unnest(c.conkey, c.confkey) AS k(child, ref) ON true
                  JOIN pg_attribute a  ON a.attrelid  = c.conrelid  AND a.attnum  = k.child
                  JOIN pg_attribute ra ON ra.attrelid = c.confrelid AND ra.attnum = k.ref
                 WHERE c.contype = \'f\'
                   AND c.confrelid = to_regclass(?)';
        $params = [$table];

        if (null !== $column) {
            $sql .= ' AND ra.attname = ?';
            $params[] = $column;
        }

        $references = [];
        foreach ($session->fetchAll($sql, $params) as $row) {
            $rule = isset($row['delete_rule']) ? (string) $row['delete_rule'] : '';
            $references[] = new InboundReference(
                childTable: (string) $row['child_table'],
                childColumn: (string) $row['child_column'],
                constraintName: (string) $row['constraint_name'],
                referencedColumn: (string) $row['referenced_column'],
                onDelete: self::DELETE_ACTIONS[$rule] ?? null,
            );
        }

        return $references;
    }
}
