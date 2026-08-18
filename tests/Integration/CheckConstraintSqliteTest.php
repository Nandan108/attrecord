<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\CheckConstraintCases;
use Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase;

/** @group sqlite */
final class CheckConstraintSqliteTest extends SqliteIntegrationTestCase
{
    use CheckConstraintCases;
}
