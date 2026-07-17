<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

/** String-backed enum fixture — drives the ENUM-column value-derivation test (no inline enumValues). */
enum SampleKind: string
{
    case Alpha = 'alpha';

    case Beta = 'beta';
}
