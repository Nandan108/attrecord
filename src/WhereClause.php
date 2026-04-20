<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

// ---- Internal node types ---------------------------------------------------
// Implementation details of WhereClause — not part of the public API.

/** @internal */
interface WhereNode
{
}

/** @internal */
final readonly class WhereNode_Leaf implements WhereNode
{
    public function __construct(
        public string $col,
        public string $op,
        public int | float | string | bool | null $value,
    ) {
    }
}

/** @internal */
final readonly class WhereNode_Not implements WhereNode
{
    public function __construct(
        public WhereClause $child,
    ) {
    }
}

/** @internal */
final readonly class WhereNode_Between implements WhereNode
{
    public function __construct(
        public string $col,
        public int | float | string $low,
        public int | float | string $high,
        public bool $negate = false,
    ) {
    }
}

/** @internal */
final readonly class WhereNode_In implements WhereNode
{
    /** @param list<scalar|null> $values */
    public function __construct(
        public string $col,
        public array $values,
        public bool $negate = false,
    ) {
    }
}

/** @internal */
final readonly class WhereNode_InTuples implements WhereNode
{
    /**
     * @param list<string>            $cols
     * @param list<list<scalar|null>> $rows
     */
    public function __construct(
        public array $cols,
        public array $rows,
        public bool $negate = false,
    ) {
    }
}

/** @internal */
final readonly class WhereNode_Raw implements WhereNode
{
    /** @param list<scalar|null> $params */
    public function __construct(
        public string $sql,
        public array $params,
    ) {
    }
}

/** @internal */
final readonly class WhereNode_Compound implements WhereNode
{
    /**
     * @param 'AND'|'OR'        $op
     * @param list<WhereClause> $parts
     */
    public function __construct(
        public string $op,
        public array $parts,
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
     * @param list<scalar|null> $params
     */
    public static function whereRaw(string $sql, array $params = []): self
    {
        return new self(new WhereNode_Raw($sql, $params));
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
     * @return list<scalar|null>
     */
    public function params(): array
    {
        return self::collectParams($this->node);
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
            $node instanceof WhereNode_Raw       => "({$node->sql})",
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
    private static function collectParams(WhereNode $node): array
    {
        return match (true) {
            $node instanceof WhereNode_Leaf     => null !== $node->value ? [$node->value] : [],
            $node instanceof WhereNode_In       => $node->values,
            $node instanceof WhereNode_InTuples => empty($node->rows)
                ? []
                : array_merge(...$node->rows),
            $node instanceof WhereNode_Raw      => $node->params,
            $node instanceof WhereNode_Compound => empty($node->parts)
                ? []
                : array_merge(
                    ...array_map(
                        fn (WhereClause $c): array => self::collectParams($c->node),
                        $node->parts,
                    ),
                ),
            $node instanceof WhereNode_Between  => [$node->low, $node->high],
            $node instanceof WhereNode_Not      => self::collectParams($node->child->node),
            default                             => throw new \LogicException('Unknown WhereNode type: '.get_debug_type($node)),
        };
    }
}
