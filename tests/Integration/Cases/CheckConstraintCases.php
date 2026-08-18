<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Tests\Fixtures\DdlCheckRecord;

/**
 * Shared cases for class-level #[Check]: the constraints are not merely emitted, the engine
 * enforces them.
 *
 * That distinction is worth a real database. MySQL before 8.0.16 parses a CHECK clause and
 * *ignores* it, so a DDL-string assertion cannot tell an enforced constraint from a decorative
 * one — only a rejected write can.
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase|\Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase
 */
trait CheckConstraintCases
{
    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        return [DdlCheckRecord::class];
    }

    public function testAConformingRowIsAccepted(): void
    {
        $r = new DdlCheckRecord();
        $r->kind = 'unit';
        $r->tracking = 'lot';
        $r->save();

        $this->assertNotNull($r->id);
        $this->assertNotNull(DdlCheckRecord::getOne($r->id));
    }

    public function testARowBreakingTheConditionalRuleIsRejected(): void
    {
        // "only a unit may be tracked" — an aggregate carrying lot tracking is exactly the write
        // the application guards against, arriving from something that never passed through it.
        $r = new DdlCheckRecord();
        $r->kind = 'aggregate';
        $r->tracking = 'lot';

        $this->expectException(\Throwable::class);
        $r->save();
    }

    public function testARowBreakingTheConditionalNotNullIsRejected(): void
    {
        // "a batch must name its parent" — a NOT NULL that applies to one kind of row only, which
        // is the shape no column definition can express.
        $r = new DdlCheckRecord();
        $r->kind = 'batch';
        $r->tracking = 'none';
        $r->parent_id = null;

        $this->expectException(\Throwable::class);
        $r->save();
    }

    public function testTheRejectedRowIsNotStored(): void
    {
        $r = new DdlCheckRecord();
        $r->kind = 'aggregate';
        $r->tracking = 'serial';

        try {
            $r->save();
        } catch (\Throwable) {
            // expected — the point is what the table holds afterwards
        }

        $this->assertSame(0, DdlCheckRecord::countWhere('kind = ?', ['aggregate']));
    }
}
