<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * Wraps a raw SQL fragment with optional bound parameters.
 *
 * Used as the shared value object for "untranslated SQL" escape hatches across
 * attrecord — currently:
 *  - SET-clause values in {@see Record::updateWhere()} (column → RawSql)
 *  - WHERE conditions via {@see WhereClause::whereRaw()}
 *
 * The expression is embedded verbatim. The caller is responsible for quoting any
 * identifiers and binding values via `?` placeholders — the dialect is not consulted.
 * Use only with trusted, static SQL fragments such as function calls, CASE
 * expressions, or column-to-column assignments. Never pass user-supplied input as
 * the expression string.
 *
 * Examples:
 *   // Parameterless raw fragment (SET position)
 *   $rec::updateWhere(
 *       ['score' => new RawSql('CASE `status` WHEN "a" THEN 1 ELSE 0 END')],
 *       '`group_id` = ?', [$groupId],
 *   );
 *
 *   // Parameterised raw fragment (SET position)
 *   $rec::updateWhere(
 *       ['priority' => new RawSql('GREATEST(?, `priority`)', [5])],
 *       '`status` = ?', ['pending'],
 *   );
 *
 *   // Same RawSql reused as a WHERE condition
 *   $jsonHas = new RawSql('JSON_CONTAINS(`tags`, ?)', ['"featured"']);
 *   $orders  = Order::find(WhereClause::whereRaw($jsonHas));
 *
 * @api
 */
final class RawSql
{
    /**
     * @param string            $expression Raw SQL fragment with optional `?` placeholders
     * @param list<scalar|null> $params     Values bound to the `?` placeholders in $expression
     */
    public function __construct(
        public readonly string $expression,
        public readonly array $params = [],
    ) {
    }
}
