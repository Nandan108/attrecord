<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\RecordCrudCases;
use Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase;

/** @group sqlite */
final class RecordCrudSqliteTest extends SqliteIntegrationTestCase
{
    use RecordCrudCases;
}
