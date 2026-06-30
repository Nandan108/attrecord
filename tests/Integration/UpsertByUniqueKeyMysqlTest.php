<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\UpsertByUniqueKeyCases;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

/** @group mysql */
final class UpsertByUniqueKeyMysqlTest extends IntegrationTestCase
{
    use UpsertByUniqueKeyCases;
}
