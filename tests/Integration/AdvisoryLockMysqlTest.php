<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\AdvisoryLockCases;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

/** @group mysql */
final class AdvisoryLockMysqlTest extends IntegrationTestCase
{
    use AdvisoryLockCases;
}
