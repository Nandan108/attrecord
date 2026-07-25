<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

/**
 * Int-backed, power-of-two enum fixture for {@see \Nandan108\Attrecord\Caster\BitmaskCaster} —
 * models a flag-set (a subject's stock concerns), each case owning one bit. Non-contiguous bits are
 * fine; the values just have to be distinct powers of two.
 */
enum StockConcern: int
{
    case Deficit = 1;
    case Overstock = 2;
    case Stale = 4;
    case NoCost = 8;
}
