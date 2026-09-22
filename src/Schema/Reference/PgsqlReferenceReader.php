<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema\Reference;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Schema\AbstractReferenceReader;

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
    protected function readInbound(DbSession $session, string $table): array
    {
        // `WITH ORDINALITY` keeps the pairs in constraint order, which is the constraint: the
        // members of a multi-column key are only meaningful paired, and in the order declared.
        $sql = 'SELECT c.conrelid::regclass::text AS child_table,
                       a.attname                  AS child_column,
                       c.conname                  AS constraint_name,
                       ra.attname                 AS referenced_column,
                       c.confdeltype              AS delete_rule
                  FROM pg_constraint c
                  JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(child, ref, ord) ON true
                  JOIN pg_attribute a  ON a.attrelid  = c.conrelid  AND a.attnum  = k.child
                  JOIN pg_attribute ra ON ra.attrelid = c.confrelid AND ra.attnum = k.ref
                 WHERE c.contype = \'f\'
                   AND c.confrelid = to_regclass(?)
                 ORDER BY c.conrelid, c.conname, k.ord';

        $rows = [];
        foreach ($session->fetchAll($sql, [$table]) as $row) {
            $rows[] = [
                'table'      => (string) $row['child_table'],
                'constraint' => (string) $row['constraint_name'],
                'child'      => (string) $row['child_column'],
                'referenced' => (string) $row['referenced_column'],
                'onDelete'   => isset($row['delete_rule']) ? (string) $row['delete_rule'] : null,
            ];
        }

        return self::assemble($rows);
    }

    /**
     * PostgreSQL spells a referential action as a single character, so the shared mapping (which
     * reads the word) cannot serve here.
     */
    #[\Override]
    protected static function action(?string $raw): ?ForeignKeyAction
    {
        return null === $raw ? null : (self::DELETE_ACTIONS[$raw] ?? null);
    }
}
