<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\UpsertAllTimestampCases;
use Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase;

/** @group sqlite */
final class UpsertAllTimestampSqliteTest extends SqliteIntegrationTestCase
{
    use UpsertAllTimestampCases;
}
