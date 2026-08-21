<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\ReferenceReaderCases;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

/** @group mysql */
final class ReferenceReaderMysqlTest extends IntegrationTestCase
{
    use ReferenceReaderCases;
}
