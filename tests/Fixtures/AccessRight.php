<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

/** String-backed enum fixture for {@see \Nandan108\Attrecord\Caster\SetCaster} — SET member names. */
enum AccessRight: string
{
    case Read = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
