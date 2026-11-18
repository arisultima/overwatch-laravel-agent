<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests\Fixtures;

/**
 * A role held as a backed enum, as some apps cast a role column.
 */
enum Role: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';
}
