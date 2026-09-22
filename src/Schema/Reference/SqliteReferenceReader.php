<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema\Reference;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Schema\AbstractReferenceReader;

/**
 * Inbound foreign keys on SQLite, which has no constraint catalogue — `PRAGMA foreign_key_list(t)`
 * reports one table's *outbound* keys and there is no inbound index at all.
 *
 * The obvious consequence would be a pragma per table in a loop. It is avoidable: since 3.16 every
 * pragma is also a **table-valued function**, so `pragma_foreign_key_list(m.name)` can be joined
 * against `sqlite_master` and the whole schema answered in one statement, filtered on the referenced
 * table like the other two engines. attrecord requires 3.33 for other reasons, comfortably above.
 *
 * Two SQLite-isms in the result. The pragma reports `to` as NULL when the constraint names no target
 * column — `REFERENCES parent` alone, meaning the parent's primary key — so it is resolved rather
 * than passed on as null; a caller filtering by column should not have to know that spelling exists.
 * And identifiers come back exactly as written in the DDL, quotes and case included, which is what
 * `sqlite_master` stores.
 */
final class SqliteReferenceReader extends AbstractReferenceReader
{
    #[\Override]
    protected function readInbound(DbSession $session, string $table): array
    {
        // `f.id` is the constraint's ordinal within the child table and `f.seq` the column's place
        // within that constraint — so `id` groups a multi-column key and `seq` orders its pairs.
        $sql = 'SELECT m.name AS child_table,
                       f.id   AS constraint_id,
                       f."from" AS child_column,
                       f."to"   AS referenced_column,
                       f.on_delete
                  FROM sqlite_master m
                  JOIN pragma_foreign_key_list(m.name) f
                 WHERE m.type = \'table\'
                   AND m.name NOT LIKE \'sqlite_%\'
                   AND f."table" = ?
                 ORDER BY m.name, f.id, f.seq';

        $primaryKey = null;
        $rows = [];

        foreach ($session->fetchAll($sql, [$table]) as $row) {
            // A null `to` means "the parent's primary key", spelled by omission. Resolved once, and
            // only when some constraint actually uses that form.
            $referencedColumn = isset($row['referenced_column'])
                ? (string) $row['referenced_column']
                : ($primaryKey ??= $this->primaryKeyOf($session, $table));

            $rows[] = [
                'table' => (string) $row['child_table'],
                // SQLite does not name a foreign key in any queryable place; the pragma reports an
                // ordinal, not a name. A synthetic one keeps the read-model honest about identity
                // instead of inventing a fake match for whatever the DDL might have called it.
                // Built from the constraint's *id* rather than its column, so the members of a
                // multi-column key assemble into one reference rather than one rule per column.
                'constraint' => 'fk#'.((string) ($row['constraint_id'] ?? '0')),
                'child'      => (string) $row['child_column'],
                'referenced' => $referencedColumn,
                'onDelete'   => isset($row['on_delete']) ? (string) $row['on_delete'] : null,
            ];
        }

        return self::assemble($rows);
    }

    /** The single-column primary key of `$table`, or `'rowid'` when it has none declared. */
    private function primaryKeyOf(DbSession $session, string $table): string
    {
        foreach ($session->fetchAll('SELECT name, pk FROM pragma_table_info(?)', [$table]) as $row) {
            if (1 === (int) ($row['pk'] ?? 0)) {
                return (string) $row['name'];
            }
        }

        return 'rowid';
    }
}
