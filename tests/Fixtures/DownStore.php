<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests\Fixtures;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store that is down: every call throws, as a Redis or database
 * store does when its server cannot be reached.
 *
 * Except schedule:run's own flags: it reads its pause flag and clears its
 * interrupt flag on the default store before any event runs (which, and
 * whether that can be turned off, depends on the Laravel release), so a
 * store down for those stops the schedule whatever the package does. Here
 * they read as unset and clear without error, so the test sees only what
 * the scheduled entries themselves touch.
 */
final class DownStore implements LockProvider, Store
{
    public function get($key)
    {
        if (is_string($key) && str_starts_with($key, 'illuminate:schedule:')) {
            return null;
        }

        $this->down();
    }

    public function many(array $keys)
    {
        $this->down();
    }

    public function put($key, $value, $seconds)
    {
        $this->down();
    }

    public function putMany(array $values, $seconds)
    {
        $this->down();
    }

    public function increment($key, $value = 1)
    {
        $this->down();
    }

    public function decrement($key, $value = 1)
    {
        $this->down();
    }

    public function forever($key, $value)
    {
        $this->down();
    }

    public function touch($key, $seconds)
    {
        $this->down();
    }

    public function forget($key)
    {
        if (is_string($key) && str_starts_with($key, 'illuminate:schedule:')) {
            return true;
        }

        $this->down();
    }

    public function flush()
    {
        $this->down();
    }

    public function getPrefix()
    {
        return '';
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->down();
    }

    public function restoreLock($name, $owner)
    {
        $this->down();
    }

    private function down(): never
    {
        throw new RuntimeException('the cache is down');
    }
}
