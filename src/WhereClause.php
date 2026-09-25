<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Schema\TableSchema;

// ---- Internal node types ---------------------------------------------------
// Implementation details of WhereClause — not part of the public API.

/** @internal */
interface WhereNode
{
}

/** @internal */
final class WhereNode_Leaf implements WhereNode
{
    public function __construct(
        public readonly string $col,
        public readonly string $op,
        public readonly int | float | string | bool | null $value,
    ) {
    }
}

/** @internal */
final class WhereNode_Not implements WhereNode
{
    public function __construct(
        public readonly WhereClause $child,
    ) {
    }
}

/** @internal */
final class WhereNode_Between implements WhereNode
{
    public function __construct(
        public readonly string $col,
        public readonly int | float | string $low,
        public readonly int | float | string $high,
        public readonly bool $negate = false,
    ) {
    }
}

/** @internal */
final class WhereNode_In implements WhereNode
{
    /** @param list<scalar|null> $values */
    public function __construct(
        public readonly string $col,
        public readonly array $values,
        public readonly bool $negate = false,
    ) {
    }
}

/** @internal */
final class WhereNode_InTuples implements WhereNode
{
    /**
     * @param list<string>            $cols
     * @param list<list<scalar|null>> $rows
     */
    public function __construct(
        public readonly array $cols,
        public readonly array $rows,
        public readonly bool $negate = false,
    ) {
    }
}

/** @internal */
final class WhereNode_Raw implements WhereNode
{
    public function __construct(
        public readonly RawSql $raw,
    ) {
    }
}

/** @internal */
final class WhereNode_Compound implements WhereNode
{
    /**
     * @param 'AND'|'OR'        $op
     * @param list<WhereClause> $parts
     */
    public function __construct(
        public readonly string $op,
        public readonly array $parts,
    ) {
    }
}

// ---- Public API ------------------------------------------------------------

/**
 * Immutable SQL WHERE-clause fragment builder.
 *
 * Stores a semantic tree of conditions. Call render($dialect) to produce a
 * parameterised SQL fragment with identifiers quoted for the target database.
 * Call render(null) for unquoted debug output only — do NOT use as live SQL.
 *
 * Column names passed to the factory methods are stored unquoted and quoted
 * at render time. Use Record::where() / whereIn() / whereInTuples() for
 * auto-quoting convenience, or pass pre-quoted expressions to whereRaw().
 *
 * Usage:
 *
 *   $clause = WhereClause::where('status', 'pending')
 *       ->andWhere(
 *           WhereClause::where('total', 100, '>')
 *               ->orWhere(WhereClause::where('total', null))
 *       );
 *
 *   $orders = Order::find($clause);   // dialect applied automatically via find()
 *
 * @api
 */
final class WhereClause
{
    private WhereNode $node;

    private function __construct(WhereNode $node)
    {
        $this->node = $node;
    }

    // -----------------------------------------------------------------
    // Factory methods
    // -----------------------------------------------------------------

    /** Single-column comparison condition. */
    public static function where(string $col, int | float | string | bool | null $value, string $op = '='): self
    {
        return new self(new WhereNode_Leaf($col, $op, $value));
    }

    /**
     * All-columns-equal (AND-ed) condition from a non-empty `column => value` map — the multi-column
     * sibling of {@see self::where()}. A clean way to target `updateWhere()` / `countWhere()` /
     * `deleteWhere()` / `find()` without hand-writing a SQL fragment + positional params; column
     * names are auto-quoted at render time.
     *
     * Values are matched as raw scalars (no column caster is applied), so match an enum/VO column by
     * its stored scalar — e.g. `match(['status' => $status->value])`, mirroring the "->value at the
     * SQL boundary" convention.
     *
     * @param array<string, scalar|null> $match
     */
    public static function match(array $match): self
    {
        if ([] === $match) {
            throw new Exception\AttrecordException('WhereClause::match() requires a non-empty match map.');
        }

        /** @var self|null $clause */
        $clause = null;
        foreach ($match as $col => $value) {
            $cond = self::where($col, $value);
            $clause = null === $clause ? $cond : $clause->andWhere($cond);
        }

        return $clause;
    }

    /**
     * IN-list condition — single or multi-column.
     *
     * Single column:   whereIn('status', ['pending', 'confirmed'])
     * Multiple columns: whereIn(['status', 'type'], [['pending', 'order'], ['draft', 'quote']])
     *
     * Returns a false clause `(1 = 0)` when $values is empty.
     *
     * @param string|list<string>                       $col    unquoted column name, or list of names
     * @param list<scalar|null>|list<list<scalar|null>> $values flat list for single-column;
     *                                                          list of rows for multi-column
     */
    public static function whereIn(string | array $col, array $values, bool $negate = false): self
    {
        if (\is_array($col)) {
            /** @var list<list<scalar|null>> $values */
            return self::whereInTuples($col, $values, $negate);
        }

        /** @var list<scalar|null> $values */
        return new self(new WhereNode_In($col, $values, $negate));
    }

    /**
     * NOT IN-list condition — single or multi-column.
     *
     * Single column:   whereNotIn('status', ['pending', 'confirmed'])
     * Multiple columns: whereNotIn(['status', 'type'], [['pending', 'order'], ['draft', 'quote']])
     *
     * Returns a false clause `(1 = 0)` when $values is empty.
     *
     * @param string|list<string>                       $col    unquoted column name, or list of names
     * @param list<scalar|null>|list<list<scalar|null>> $values flat list for single-column;
     *                                                          list of rows for multi-column
     */
    public static function whereNotIn(string | array $col, array $values): self
    {
        return self::whereIn($col, $values, true);
    }

    /**
     * Multi-column IN condition using row-value constructors.
     *
     * Produces: `((`col1`, `col2`) IN ((?, ?), (?, ?), …))`
     *
     * Useful for composite index seeks. Supported by MySQL/MariaDB and PostgreSQL;
     * not by SQLite.
     *
     * Returns a false clause `(1 = 0)` when $rows or $cols is empty.
     *
     * @param list<string>            $cols unquoted column names
     * @param list<list<scalar|null>> $rows each inner list must be the same length as $cols
     */
    public static function whereInTuples(array $cols, array $rows, bool $negate = false): self
    {
        return new self(new WhereNode_InTuples($cols, $rows, $negate));
    }

    /**
     * Multi-column NOT IN condition using row-value constructors.
     *
     * Produces: `((`col1`, `col2`) NOT IN ((?, ?), (?, ?), …))`
     *
     * Useful for composite index seeks. Supported by MySQL/MariaDB and PostgreSQL;
     * not by SQLite.
     *
     * Returns a false clause `(1 = 0)` when $rows or $cols is empty.
     *
     * @param list<string>            $cols unquoted column names
     * @param list<list<scalar|null>> $rows each inner list must be the same length as $cols
     */
    public static function whereNotInTuples(array $cols, array $rows): self
    {
        return self::whereInTuples($cols, $rows, true);
    }

    /**
     * Raw SQL fragment escape hatch for conditions the builder cannot express natively
     * (JSON operators, subqueries, REGEXP, full-text MATCH … AGAINST, …).
     *
     * Caller is responsible for quoting identifiers and binding values via ? placeholders.
     * The dialect passed to render() is ignored for this node.
     *
     * Accepts either a SQL string (+ optional params), or a pre-built {@see RawSql}.
     * Passing a `RawSql` lets the same expression be reused in both SET and WHERE
     * positions, or composed by helper functions.
     *
     * @param string|RawSql     $sql    raw SQL fragment, or a pre-built RawSql value
     * @param list<scalar|null> $params bound values; ignored when $sql is a RawSql
     */
    public static function whereRaw(string | RawSql $sql, array $params = []): self
    {
        $raw = $sql instanceof RawSql ? $sql : new RawSql($sql, $params);

        return new self(new WhereNode_Raw($raw));
    }

    public static function whereLike(string $col, string $pattern): self
    {
        return new self(new WhereNode_Leaf($col, 'LIKE', $pattern));
    }

    public static function whereNotLike(string $col, string $pattern): self
    {
        return new self(new WhereNode_Leaf($col, 'NOT LIKE', $pattern));
    }

    public static function whereNot(WhereClause $clause): self
    {
        return new self(new WhereNode_Not($clause));
    }

    public static function whereBetween(string $col, int | float | string $low, int | float | string $high): self
    {
        return new self(new WhereNode_Between($col, $low, $high));
    }

    public static function whereNotBetween(string $col, int | float | string $low, int | float | string $high): self
    {
        return new self(new WhereNode_Between($col, $low, $high, true));
    }

    public static function whereNone(WhereClause ...$clauses): self
    {
        return self::whereNot(self::whereAny(...$clauses));
    }

    // -----------------------------------------------------------------
    // Combinators
    // -----------------------------------------------------------------

    public static function whereAll(WhereClause $first, WhereClause $second, WhereClause ...$rest): self
    {
        return new self(new WhereNode_Compound('AND', array_values([$first, $second, ...$rest])));
    }

    public static function whereAny(WhereClause $first, WhereClause $second, WhereClause ...$rest): self
    {
        return new self(new WhereNode_Compound('OR', array_values([$first, $second, ...$rest])));
    }

    /** Combine with one or more clauses using AND. */
    public function andWhere(WhereClause $first, WhereClause ...$rest): self
    {
        return new self(new WhereNode_Compound('AND', array_values([$this, $first, ...$rest])));
    }

    /** Combine with one or more clauses using OR. */
    public function orWhere(WhereClause $first, WhereClause ...$rest): self
    {
        return new self(new WhereNode_Compound('OR', array_values([$this, $first, ...$rest])));
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * Render the clause to a SQL fragment with ? placeholders.
     *
     * Pass a SqlDialect to have column names properly quoted for the target database.
     * Pass null for unquoted debug output — NOT safe to use as live SQL.
     */
    public function render(?SqlDialect $dialect = null): string
    {
        return self::renderNode($this->node, $dialect);
    }

    /**
     * Bound parameter values in positional order, matching the ? placeholders in render().
     *
     * Dialect-independent — the same values are used regardless of which database is targeted.
     *
     * PHP booleans are normalized to their canonical SQL scalar form (`true` → 1, `false` → 0). A raw
     * bool has exactly one correct scalar mapping for any column, yet DB drivers disagree on how to
     * bind one — some interpolating sessions reject it outright, and PDO's emulated prepares bind
     * `false` as an empty string — so `where('active', true)` would otherwise be a cross-driver
     * footgun. Normalizing here (the single param boundary) keeps that value symmetric with what a
     * bool column serializes to on write, without any column-cast introspection.
     *
     * ## Binary columns (v0.24+)
     *
     * Given a schema and a dialect that {@see SqlDialect::bindsBinaryAsLob()}, a value compared
     * against a **binary column** is wrapped in a {@see BinaryParam} — the same type-driven marking
     * the write path already does, extended to predicates. Without it, `where('order_id', $bytes)`
     * binds raw bytes as a text parameter: PostgreSQL rejects that outright ("Character not in
     * repertoire") and a translator layer may quietly match nothing.
     *
     * **This wraps; it deliberately does not serialize.** Routing predicate values through
     * `ColumnSerializer::toParam()` would re-apply the column's *caster*, and casters are written
     * for the PHP-typed values of the write path, not for the already-scalar value a predicate
     * carries — a `JsonCaster` would re-encode `'active'` to `'"active"'` and match nothing, and an
     * `EnumCaster` expects a backed enum this signature cannot even accept. So the wrap is narrow
     * on purpose: a binary column, **no caster**, and a string value. Everything else passes
     * through byte-identical, which is what keeps the bool normalization above the only
     * column-independent transformation here.
     *
     * A column the schema does not declare — a joined table's, or an alias — passes through
     * untouched rather than throwing. And a {@see RawSql} predicate carries no column association
     * at all, so its parameters are never wrapped; bind a `BinaryParam` explicitly there.
     *
     * @param TableSchema|null $schema          the schema the predicate's columns belong to; without it nothing is wrapped
     * @param bool             $bindBinaryAsLob from {@see SqlDialect::bindsBinaryAsLob()}
     *
     * @return list<int|float|string|BinaryParam|null>
     */
    public function params(?TableSchema $schema = null, bool $bindBinaryAsLob = false): array
    {
        $out = [];
        foreach (self::collectPairs($this->node) as [$value, $col]) {
            if (\is_bool($value)) {
                $value = (int) $value;
            }

            if ($bindBinaryAsLob && null !== $schema && null !== $col && \is_string($value)) {
                $def = $schema->columns[$col] ?? null;
                if (null !== $def && $def->isBinary && null === $def->caster) {
                    $value = new BinaryParam($value);
                }
            }

            $out[] = $value;
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Private rendering helpers
    // -----------------------------------------------------------------

    private static function renderNode(WhereNode $node, ?SqlDialect $dialect): string
    {
        /** @var \Closure(string): string $qi */
        $qi = null !== $dialect
            ? static fn (string $col): string => $dialect->quoteIdentifier($col)
            : static fn (string $col): string => $col;

        return match (true) {
            $node instanceof WhereNode_Leaf      => self::renderLeaf($node, $qi, $dialect),
            $node instanceof WhereNode_In        => self::renderIn($node, $qi),
            $node instanceof WhereNode_InTuples  => self::renderInTuples($node, $qi),
            $node instanceof WhereNode_Raw       => "({$node->raw->expression})",
            $node instanceof WhereNode_Compound  => self::renderCompound($node, $dialect),
            $node instanceof WhereNode_Between   => self::renderBetween($node, $qi),
            $node instanceof WhereNode_Not       => self::renderNot($node, $dialect),
            default                              => throw new \LogicException('Unknown WhereNode type: '.get_debug_type($node)),
        };
    }

    /** @param \Closure(string): string $qi */
    private static function renderLeaf(WhereNode_Leaf $node, \Closure $qi, ?SqlDialect $dialect): string
    {
        $qcol = $qi($node->col);

        if (null === $node->value) {
            $opSql = ('!=' === $node->op || '<>' === $node->op) ? 'IS NOT NULL' : 'IS NULL';

            return "({$qcol} {$opSql})";
        }

        $escapeSuffix = ('LIKE' === $node->op || 'NOT LIKE' === $node->op)
            ? ($dialect?->likeEscapeSuffix() ?? '')
            : '';

        return "({$qcol} {$node->op} ?{$escapeSuffix})";
    }

    /** @param \Closure(string): string $qi */
    private static function renderIn(WhereNode_In $node, \Closure $qi): string
    {
        if (empty($node->values)) {
            return '(1 = 0)';
        }

        $placeholders = implode(', ', array_fill(0, \count($node->values), '?'));
        $not = $node->negate ? 'NOT ' : '';

        return "({$qi($node->col)} {$not}IN ({$placeholders}))";
    }

    /** @param \Closure(string): string $qi */
    private static function renderInTuples(WhereNode_InTuples $node, \Closure $qi): string
    {
        if (empty($node->rows) || empty($node->cols)) {
            return '(1 = 0)';
        }

        $colList = implode(', ', array_map($qi, $node->cols));
        $rowSql = array_map(
            fn (array $row): string => '('.implode(', ', array_fill(0, \count($row), '?')).')',
            $node->rows,
        );

        $not = $node->negate ? 'NOT ' : '';

        return "(({$colList}) {$not}IN (".implode(', ', $rowSql).'))';
    }

    private static function renderCompound(WhereNode_Compound $node, ?SqlDialect $dialect): string
    {
        $parts = array_map(
            fn (WhereClause $c): string => self::renderNode($c->node, $dialect),
            $node->parts,
        );

        return '('.implode(" {$node->op} ", $parts).')';
    }

    /** @param \Closure(string): string $qi */
    private static function renderBetween(WhereNode_Between $node, \Closure $qi): string
    {
        $qcol = $qi($node->col);
        $not = $node->negate ? 'NOT ' : '';

        return "({$qcol} {$not}BETWEEN ? AND ?)";
    }

    private static function renderNot(WhereNode_Not $node, ?SqlDialect $dialect): string
    {
        $childSql = self::renderNode($node->child->node, $dialect);

        return "(NOT {$childSql})";
    }

    // -----------------------------------------------------------------
    // Private param collection
    // -----------------------------------------------------------------

    /**
     * @return list<scalar|null>
     */
    /**
     * Every bound value in positional order, each paired with the column it is compared against —
     * or `null` where there is none to know.
     *
     * The pairing is what lets {@see params()} mark a binary value from the schema instead of
     * guessing from the bytes. Order is the contract: it must stay identical to the `?` placeholders
     * {@see renderNode()} emits, so each arm mirrors its renderer.
     *
     * Four node kinds carry a column association and all four are handled — a value compared
     * (`Leaf`), a range (`Between`, whose two bounds share one column), a list (`In`), and a tuple
     * list (`InTuples`, where each row's values pair **positionally** with `$cols`; that is the
     * composite-key `(a, b) IN ((…), …)` form). `Raw` deliberately yields `null` columns: its SQL is
     * opaque here, so nothing can be inferred about what its parameters are compared to.
     *
     * @return list<array{0: scalar|null, 1: string|null}>
     */
    private static function collectPairs(WhereNode $node): array
    {
        return match (true) {
            $node instanceof WhereNode_Leaf     => null !== $node->value ? [[$node->value, $node->col]] : [],
            $node instanceof WhereNode_In       => array_map(
                static fn (int | float | string | bool | null $v): array => [$v, $node->col],
                $node->values,
            ),
            $node instanceof WhereNode_InTuples => empty($node->rows)
                ? []
                : array_merge(...array_map(
                    static fn (array $row): array => array_map(
                        static fn (int | float | string | bool | null $v, int $i): array => [$v, $node->cols[$i] ?? null],
                        $row,
                        array_keys($row),
                    ),
                    $node->rows,
                )),
            $node instanceof WhereNode_Raw      => array_map(
                static fn (mixed $v): array => [$v, null],
                $node->raw->params,
            ),
            $node instanceof WhereNode_Compound => empty($node->parts)
                ? []
                : array_merge(
                    ...array_map(
                        fn (WhereClause $c): array => self::collectPairs($c->node),
                        $node->parts,
                    ),
                ),
            $node instanceof WhereNode_Between  => [[$node->low, $node->col], [$node->high, $node->col]],
            $node instanceof WhereNode_Not      => self::collectPairs($node->child->node),
            default                             => throw new \LogicException('Unknown WhereNode type: '.get_debug_type($node)),
        };
    }
}
