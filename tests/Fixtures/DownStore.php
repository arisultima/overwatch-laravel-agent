<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests\Fixtures;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store that is down: every call throws, as a Redis or database
 * store does when its server cannot be reached.
 */
final class DownStore implements LockProvider, Store
{
    public function get($key)
    {
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
