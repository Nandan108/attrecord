<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration;

use Nandan108\Attrecord\Tests\Integration\Cases\PolymorphicRelationCases;
use Nandan108\Attrecord\Tests\Support\IntegrationTestCase;

/** @group mysql */
final class PolymorphicRelationMysqlTest extends IntegrationTestCase
{
    use PolymorphicRelationCases;
}
