<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\BinaryPredicateCases;
use Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase;

/** @group pgsql */
final class BinaryPredicatePgsqlTest extends PgsqlIntegrationTestCase
{
    use BinaryPredicateCases;
}
