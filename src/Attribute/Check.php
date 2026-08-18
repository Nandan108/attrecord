<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Declares a table-level CHECK constraint: a boolean SQL expression every row must satisfy.
 *
 *     #[Table(name: 'subjects')]
 *     #[Check('tracking_unit_only', "kind = 'unit' OR tracking = 'none'")]
 *     #[Check('batch_has_parent', "kind <> 'batch' OR parent_id IS NOT NULL")]
 *     final class Subject extends Record { ... }
 *
 * Class-level and repeatable, like the class-level form of {@see Index} and {@see ForeignKey}.
 *
 * **The expression is passed through to the engine verbatim.** This library does not parse it,
 * which is what lets the full expression language of each engine be used — and equally means
 * portable SQL is the author's business: an expression using a MySQL-only function will fail at
 * `CREATE TABLE` on PostgreSQL, loudly, which is the right moment to find out.
 *
 * A CHECK is a **row-local** guarantee, and deliberately no more than that. It sees one row's
 * columns; it cannot query another table or another row (no engine allows a subquery here), and it
 * is not evaluated on rows that already exist until something rewrites them. Rules spanning rows —
 * "a batch's parent must itself be a unit" — belong in the application, with the CHECK carrying the
 * single-row projection of the rule as defence against writes that never pass through it: a CLI, a
 * neighbouring application, someone at a SQL prompt.
 *
 * Engine notes, all of them consequences of how the engines differ rather than choices made here:
 *
 * - **MySQL** enforces CHECK constraints from 8.0.16; **before that it parses and ignores them**,
 *   so on 8.0.0–8.0.15 the DDL succeeds and the guarantee is absent. MariaDB enforces from 10.2.1,
 *   PostgreSQL and SQLite always.
 * - **The emitted name is not the declared name.** MySQL scopes CHECK names per *database*, so two
 *   installs sharing one (a `wp_` site and a `blog_` site on shared hosting) would collide on an
 *   identical declaration. The name therefore carries a digest of the table prefix, exactly as a
 *   foreign-key constraint name does, plus a digest of the expression — see
 *   {@see \Nandan108\Attrecord\Schema\CheckDefinition::$constraintName} for what that second digest
 *   buys.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Check
{
    /**
     * @param string $name       constraint base name, unique per Record. Emitted as
     *                           `chk_<prefix digest>_<name>_<expression digest>`; keep it short
     *                           and descriptive, since it is what an engine names when the
     *                           constraint rejects a write.
     * @param string $expression boolean SQL expression, passed to the engine verbatim. Reference
     *                           this table's columns unquoted (`kind = 'unit'`); the engine
     *                           resolves and re-quotes them.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $expression,
    ) {
    }
}
