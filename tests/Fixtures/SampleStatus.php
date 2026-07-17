<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

/** Int-backed enum fixture for the EnumCaster tests — non-contiguous values, like a real lifecycle. */
enum SampleStatus: int
{
    case InPrep = 1;
    case Submitted = 3;
    case Received = 7;
}
