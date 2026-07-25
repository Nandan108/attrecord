<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

/**
 * Int-backed enum whose two cases deliberately share a value — exercises {@see
 * \Nandan108\Attrecord\Caster\BitmaskCaster}'s collision guard.
 *
 * Lives in its own file (not inline in the test) so PSR-4 loads it **lazily**: PHP 8.1 rejects
 * duplicate backed-enum values at *compile time*, so a test targeting 8.2+ can skip before this class
 * is ever autoloaded, and 8.1 never parses it. (8.2+ defers the duplicate check to `from()`/`tryFrom()`,
 * so `cases()` — which the caster ctor uses — sees both cases and the guard fires.)
 */
enum FlagDupBitEnum: int
{
    case A = 2;
    case B = 2;
}
