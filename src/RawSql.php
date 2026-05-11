<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

/**
 * Wraps a raw SQL expression for use as a column value in updateWhere().
 *
 * The expression is embedded verbatim in the generated SQL — no parameterisation,
 * no escaping. Use only with trusted, static SQL fragments such as function calls
 * or CASE expressions. Never pass user-supplied input through this class.
 *
 * Example:
 *   SomeRecord::updateWhere(
 *       ['score' => new RawSql('CASE status WHEN "a" THEN 1 ELSE 0 END')],
 *       'group_id = ?', [$groupId],
 *   );
 *
 * @api
 */
final class RawSql
{
    public function __construct(
        public readonly string $expression,
    ) {
    }
}
