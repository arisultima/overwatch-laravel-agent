<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * An array store whose locks fail, as a store does when its lock backend
 * (a table, a script) breaks while plain reads and writes still work.
 */
final class LockFailingStore extends ArrayStore
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        throw new RuntimeException('locks are down at /srv/app');
    }
}
