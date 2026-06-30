<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\UpsertByUniqueKeyCases;
use Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase;

/** @group pgsql */
final class UpsertByUniqueKeyPgsqlTest extends PgsqlIntegrationTestCase
{
    use UpsertByUniqueKeyCases;
}
