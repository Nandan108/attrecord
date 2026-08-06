<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

/** Int-backed enum fixture for {@see EnumDefaultRecord}. */
enum HttpStatusGroup: int
{
    case Informational = 1;
    case Success = 2;
    case Redirection = 3;
}
