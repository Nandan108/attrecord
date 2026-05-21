<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\WhereClause;
use PHPUnit\Framework\TestCase;

/** @psalm-suppress PropertyNotSetInConstructor */
final class WhereClauseTest extends TestCase
{
    // -----------------------------------------------------------------
    // Single conditions — render(null) produces unquoted debug SQL
    // -----------------------------------------------------------------

    public function testWhereEquality(): void
    {
        $c = WhereClause::where('status', 'pending');
        $this->assertSame('(status = ?)', $c->render());
        $this->assertSame(['pending'], $c->params());
    }

    public function testWhereWithCustomOp(): void
    {
        $c = WhereClause::where('total', 100, '>');
        $this->assertSame('(total > ?)', $c->render());
        $this->assertSame([100], $c->params());
    }

    public function testWhereNullProducesIsNull(): void
    {
        $c = WhereClause::where('deleted_at', null);
        $this->assertSame('(deleted_at IS NULL)', $c->render());
        $this->assertSame([], $c->params());
    }

    public function testWhereNotEqualNullProducesIsNotNull(): void
    {
        $c = WhereClause::where('deleted_at', null, '!=');
        $this->assertSame('(deleted_at IS NOT NULL)', $c->render());
        $this->assertSame([], $c->params());
    }

    public function testWhereDiamondNullProducesIsNotNull(): void
    {
        $c = WhereClause::where('deleted_at', null, '<>');
        $this->assertSame('(deleted_at IS NOT NULL)', $c->render());
    }

    // -----------------------------------------------------------------
    // Dialect quoting
    // -----------------------------------------------------------------

    public function testRenderWithMysqlDialectQuotesBackticks(): void
    {
        $c = WhereClause::where('status', 'pending');
        $this->assertSame('(`status` = ?)', $c->render(new MysqlDialect()));
    }

    public function testRenderWithPgsqlDialectQuotesDoubleQuotes(): void
    {
        $c = WhereClause::where('status', 'pending');
        $this->assertSame('("status" = ?)', $c->render(new PgsqlDialect()));
    }

    public function testRenderDialectAppliedThroughoutTree(): void
    {
        $c = WhereClause::where('status', 'pending')
            ->andWhere(WhereClause::whereIn('type', ['order', 'quote']));

        $this->assertSame(
            '((`status` = ?) AND (`type` IN (?, ?)))',
            $c->render(new MysqlDialect()),
        );
    }

    // -----------------------------------------------------------------
    // whereIn — single column
    // -----------------------------------------------------------------

    public function testWhereInSingleValue(): void
    {
        $c = WhereClause::whereIn('status', ['pending']);
        $this->assertSame('(status IN (?))', $c->render());
        $this->assertSame(['pending'], $c->params());
    }

    public function testWhereInMultipleValues(): void
    {
        $c = WhereClause::whereIn('status', ['pending', 'confirmed']);
        $this->assertSame('(status IN (?, ?))', $c->render());
        $this->assertSame(['pending', 'confirmed'], $c->params());
    }

    public function testWhereInEmptyReturnsFalseClause(): void
    {
        $c = WhereClause::whereIn('status', []);
        $this->assertSame('(1 = 0)', $c->render());
        $this->assertSame([], $c->params());
    }

    public function testWhereInArrayColumnDelegatesToWhereInTuples(): void
    {
        $c = WhereClause::whereIn(['status', 'type'], [['pending', 'order'], ['draft', 'quote']]);
        $this->assertSame('((status, type) IN ((?, ?), (?, ?)))', $c->render());
        $this->assertSame(['pending', 'order', 'draft', 'quote'], $c->params());
    }

    public function testWhereInArrayColumnEmptyRowsReturnsFalseClause(): void
    {
        $c = WhereClause::whereIn(['a', 'b'], []);
        $this->assertSame('(1 = 0)', $c->render());
    }

    // -----------------------------------------------------------------
    // whereInTuples
    // -----------------------------------------------------------------

    public function testWhereInTuplesSingleRow(): void
    {
        $c = WhereClause::whereInTuples(['status', 'type'], [['pending', 'order']]);
        $this->assertSame('((status, type) IN ((?, ?)))', $c->render());
        $this->assertSame(['pending', 'order'], $c->params());
    }

    public function testWhereInTuplesMultipleRows(): void
    {
        $c = WhereClause::whereInTuples(
            ['status', 'type'],
            [['pending', 'order'], ['draft', 'quote']],
        );
        $this->assertSame('((status, type) IN ((?, ?), (?, ?)))', $c->render());
        $this->assertSame(['pending', 'order', 'draft', 'quote'], $c->params());
    }

    public function testWhereInTuplesEmptyRowsReturnsFalseClause(): void
    {
        $c = WhereClause::whereInTuples(['a', 'b'], []);
        $this->assertSame('(1 = 0)', $c->render());
        $this->assertSame([], $c->params());
    }

    public function testWhereInTuplesEmptyColumnsReturnsFalseClause(): void
    {
        $c = WhereClause::whereInTuples([], [['x', 'y']]);
        $this->assertSame('(1 = 0)', $c->render());
    }

    // -----------------------------------------------------------------
    // whereNotIn — single column
    // -----------------------------------------------------------------

    public function testWhereNotInSingleValue(): void
    {
        $c = WhereClause::whereNotIn('status', ['cancelled']);
        $this->assertSame('(status NOT IN (?))', $c->render());
        $this->assertSame(['cancelled'], $c->params());
    }

    public function testWhereNotInMultipleValues(): void
    {
        $c = WhereClause::whereNotIn('status', ['cancelled', 'refunded']);
        $this->assertSame('(status NOT IN (?, ?))', $c->render());
        $this->assertSame(['cancelled', 'refunded'], $c->params());
    }

    public function testWhereNotInEmptyReturnsFalseClause(): void
    {
        $c = WhereClause::whereNotIn('status', []);
        $this->assertSame('(1 = 0)', $c->render());
        $this->assertSame([], $c->params());
    }

    public function testWhereNotInTuples(): void
    {
        $c = WhereClause::whereNotInTuples(
            ['status', 'type'],
            [['pending', 'order'], ['draft', 'quote']],
        );
        $this->assertSame('((status, type) NOT IN ((?, ?), (?, ?)))', $c->render());
        $this->assertSame(['pending', 'order', 'draft', 'quote'], $c->params());
    }

    // -----------------------------------------------------------------
    // whereBetween / whereNotBetween
    // -----------------------------------------------------------------

    public function testWhereBetween(): void
    {
        $c = WhereClause::whereBetween('total', 10, 500);
        $this->assertSame('(total BETWEEN ? AND ?)', $c->render());
        $this->assertSame([10, 500], $c->params());
    }

    public function testWhereNotBetween(): void
    {
        $c = WhereClause::whereNotBetween('age', 18, 65);
        $this->assertSame('(age NOT BETWEEN ? AND ?)', $c->render());
        $this->assertSame([18, 65], $c->params());
    }

    public function testWhereBetweenDialectQuotes(): void
    {
        $c = WhereClause::whereBetween('total', 10, 500);
        $this->assertSame('(`total` BETWEEN ? AND ?)', $c->render(new MysqlDialect()));
    }

    // -----------------------------------------------------------------
    // whereLike / whereNotLike
    // -----------------------------------------------------------------

    public function testWhereLike(): void
    {
        $c = WhereClause::whereLike('email', '%@example.com');
        $this->assertSame('(email LIKE ?)', $c->render());
        $this->assertSame(['%@example.com'], $c->params());
    }

    public function testWhereNotLike(): void
    {
        $c = WhereClause::whereNotLike('name', 'test%');
        $this->assertSame('(name NOT LIKE ?)', $c->render());
        $this->assertSame(['test%'], $c->params());
    }

    public function testWhereLikeMysqlDialect(): void
    {
        $c = WhereClause::whereLike('email', '%@example.com');
        // MySQL treats \ as escape by default — no ESCAPE clause needed
        $this->assertSame('(`email` LIKE ?)', $c->render(new MysqlDialect()));
    }

    public function testWhereLikePgsqlDialectAddsEscapeClause(): void
    {
        $c = WhereClause::whereLike('email', '%@example.com');
        $this->assertSame("(\"email\" LIKE ? ESCAPE '\\')", $c->render(new PgsqlDialect()));
    }

    public function testWhereNotLikePgsqlDialectAddsEscapeClause(): void
    {
        $c = WhereClause::whereNotLike('name', 'test%');
        $this->assertSame("(\"name\" NOT LIKE ? ESCAPE '\\')", $c->render(new PgsqlDialect()));
    }

    public function testEscapeLikeWildcardsRoundTrip(): void
    {
        $mysql = new MysqlDialect();
        $pg = new PgsqlDialect();

        // Both dialects produce the same escaped string
        $this->assertSame($mysql->escapeLikeWildcards('50% off_sale\\path'), $pg->escapeLikeWildcards('50% off_sale\\path'));
        $this->assertSame('50\% off\_sale\\\\path', $mysql->escapeLikeWildcards('50% off_sale\\path'));
    }

    // -----------------------------------------------------------------
    // whereNot / whereNone
    // -----------------------------------------------------------------

    public function testWhereNot(): void
    {
        $c = WhereClause::whereNot(WhereClause::where('active', false));
        $this->assertSame('(NOT (active = ?))', $c->render());
        $this->assertSame([false], $c->params());
    }

    public function testWhereNotDialectQuotes(): void
    {
        $c = WhereClause::whereNot(WhereClause::where('active', false));
        $this->assertSame('(NOT (`active` = ?))', $c->render(new MysqlDialect()));
    }

    public function testWhereNotWrapsCompound(): void
    {
        $c = WhereClause::whereNot(
            WhereClause::where('status', 'cancelled')
                ->orWhere(WhereClause::where('status', 'refunded')),
        );
        $this->assertSame('(NOT ((status = ?) OR (status = ?)))', $c->render());
        $this->assertSame(['cancelled', 'refunded'], $c->params());
    }

    public function testWhereNone(): void
    {
        $c = WhereClause::whereNone(
            WhereClause::where('status', 'cancelled'),
            WhereClause::where('status', 'refunded'),
        );
        $this->assertSame('(NOT ((status = ?) OR (status = ?)))', $c->render());
        $this->assertSame(['cancelled', 'refunded'], $c->params());
    }

    // -----------------------------------------------------------------
    // whereAll / whereAny (static combinators)
    // -----------------------------------------------------------------

    public function testWhereAll(): void
    {
        $c = WhereClause::whereAll(
            WhereClause::where('active', true),
            WhereClause::where('status', 'pending'),
        );
        $this->assertSame('((active = ?) AND (status = ?))', $c->render());
        $this->assertSame([true, 'pending'], $c->params());
    }

    public function testWhereAny(): void
    {
        $c = WhereClause::whereAny(
            WhereClause::where('status', 'pending'),
            WhereClause::where('status', 'confirmed'),
            WhereClause::where('status', 'processing'),
        );
        $this->assertSame('((status = ?) OR (status = ?) OR (status = ?))', $c->render());
        $this->assertSame(['pending', 'confirmed', 'processing'], $c->params());
    }

    // -----------------------------------------------------------------
    // whereRaw — dialect ignored, raw SQL returned verbatim
    // -----------------------------------------------------------------

    public function testWhereRaw(): void
    {
        $c = WhereClause::whereRaw('`total` BETWEEN ? AND ?', [10, 100]);
        $this->assertSame('(`total` BETWEEN ? AND ?)', $c->render());
        $this->assertSame([10, 100], $c->params());
    }

    public function testWhereRawDialectDoesNotAffectOutput(): void
    {
        $c = WhereClause::whereRaw('`total` BETWEEN ? AND ?', [10, 100]);
        $this->assertSame('(`total` BETWEEN ? AND ?)', $c->render(new PgsqlDialect()));
    }

    public function testWhereRawNoParams(): void
    {
        $c = WhereClause::whereRaw('deleted_at IS NULL');
        $this->assertSame('(deleted_at IS NULL)', $c->render());
        $this->assertSame([], $c->params());
    }

    public function testWhereRawAcceptsRawSql(): void
    {
        $raw = new RawSql('`total` BETWEEN ? AND ?', [10, 100]);
        $c = WhereClause::whereRaw($raw);

        $this->assertSame('(`total` BETWEEN ? AND ?)', $c->render());
        $this->assertSame([10, 100], $c->params());
    }

    public function testWhereRawWithRawSqlIgnoresParamsArg(): void
    {
        // params from RawSql are used; the second argument is ignored when first is a RawSql
        $raw = new RawSql('`a` = ?', [1]);
        $c = WhereClause::whereRaw($raw, [999]);

        $this->assertSame([1], $c->params());
    }

    // -----------------------------------------------------------------
    // Combinators
    // -----------------------------------------------------------------

    public function testandWhereSingleArg(): void
    {
        $c = WhereClause::where('a', 1)->andWhere(WhereClause::where('b', 2));
        $this->assertSame('((a = ?) AND (b = ?))', $c->render());
        $this->assertSame([1, 2], $c->params());
    }

    public function testandWhereMultipleArgs(): void
    {
        $c = WhereClause::where('a', 1)
            ->andWhere(WhereClause::where('b', 2), WhereClause::where('c', 3));
        $this->assertSame('((a = ?) AND (b = ?) AND (c = ?))', $c->render());
        $this->assertSame([1, 2, 3], $c->params());
    }

    public function testorWhereSingleArg(): void
    {
        $c = WhereClause::where('a', 1)->orWhere(WhereClause::where('b', 2));
        $this->assertSame('((a = ?) OR (b = ?))', $c->render());
        $this->assertSame([1, 2], $c->params());
    }

    public function testorWhereMultipleArgs(): void
    {
        $c = WhereClause::where('a', 1)
            ->orWhere(WhereClause::where('b', 2), WhereClause::where('c', 3));
        $this->assertSame('((a = ?) OR (b = ?) OR (c = ?))', $c->render());
        $this->assertSame([1, 2, 3], $c->params());
    }

    public function testNestedCombinators(): void
    {
        $c = WhereClause::where('status', 'pending')
            ->andWhere(
                WhereClause::where('total', 100, '>')
                    ->orWhere(WhereClause::where('flagged', true)),
            );

        $this->assertSame(
            '((status = ?) AND ((total > ?) OR (flagged = ?)))',
            $c->render(),
        );
        $this->assertSame(['pending', 100, true], $c->params());
    }

    public function testImmutability(): void
    {
        $base = WhereClause::where('x', 1);
        $combined1 = $base->andWhere(WhereClause::where('y', 2));
        $combined2 = $base->orWhere(WhereClause::where('z', 3));

        $this->assertSame('(x = ?)', $base->render());
        $this->assertSame([1], $base->params());

        $this->assertSame('((x = ?) AND (y = ?))', $combined1->render());
        $this->assertSame('((x = ?) OR (z = ?))', $combined2->render());
    }
}
