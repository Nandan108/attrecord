<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\ReferenceReaderCases;
use Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase;

/** @group pgsql */
final class ReferenceReaderPgsqlTest extends PgsqlIntegrationTestCase
{
    use ReferenceReaderCases;
}
