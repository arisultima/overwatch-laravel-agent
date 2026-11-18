<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Illuminate\Contracts\Cache\Repository;

/**
 * Buckets on any cache store. Each counter is its own cache key; a cache
 * cannot list its keys, so each minute also keeps a lock-free index of the
 * counter names in it: a slot count plus one key per slot.
 */
final class CacheBuckets implements Buckets
{
    public function __construct(private readonly Repository $cache) {}

    public function increment(string $instance, string $minute, string $key, int $by = 1): void
    {
        $valueKey = $this->valueKey($instance, $minute, $key);

        // add() sets the TTL when it creates the key; increment() on a
        // missing key would create one that never expires on some stores.
        // A failure between add() and index() leaves a counter the reader
        // never sees; it expires with the TTL.
        if ($this->cache->add($valueKey, $by, self::TTL)) {
            $this->index($instance, $minute, $key);

            return;
        }

        $this->cache->increment($valueKey, $by);
    }

    public function max(string $instance, string $minute, string $key, int $value): void
    {
        $valueKey = $this->valueKey($instance, $minute, $key);
        $current = $this->cache->get($valueKey);

        if (is_numeric($current) && (int) $current >= $value) {
            return;
        }

        if ($current === null && $this->cache->add($valueKey, $value, self::TTL)) {
            $this->index($instance, $minute, $key);

            return;
        }

        // Once the key exists, every raise happens under its lock.
        Locks::run($this->cache, $valueKey.':lock', function () use ($valueKey, $value): void {
            $current = $this->cache->get($valueKey);

            if (is_numeric($current) && (int) $current < $value) {
                $this->cache->put($valueKey, $value, self::TTL);
            }
        });
    }

    public function read(string $instance, array $minutes): array
    {
        $read = [];

        foreach ($minutes as $minute) {
            $counters = [];
            $valueKeys = $this->valueKeys($instance, $minute);

            if ($valueKeys !== []) {
                foreach ($this->cache->getMultiple(array_keys($valueKeys)) as $valueKey => $count) {
                    if (is_numeric($count)) {
                        $counters[$valueKeys[$valueKey]] = (int) $count;
                    }
                }
            }

            ksort($counters);
            $read[$minute] = $counters;
        }

        return $read;
    }

    public function forget(string $instance, array $minutes): void
    {
        foreach ($minutes as $minute) {
            $stem = Keys::bucket($instance, $minute);
            $slots = (int) $this->cache->get($stem.':n', 0);

            foreach (array_keys($this->valueKeys($instance, $minute)) as $valueKey) {
                $this->cache->forget($valueKey);
            }

            for ($slot = 1; $slot <= $slots; $slot++) {
                $this->cache->forget($stem.':n:'.$slot);
            }

            $this->cache->forget($stem.':n');
        }
    }

    private function valueKey(string $instance, string $minute, string $key): string
    {
        return Keys::bucket($instance, $minute).':k:'.Keys::segment($key);
    }

    /**
     * Note a counter name in the minute's index. Only the first write of a
     * counter in a minute gets here.
     */
    private function index(string $instance, string $minute, string $key): void
    {
        $stem = Keys::bucket($instance, $minute);

        $slot = $this->cache->add($stem.':n', 1, self::TTL) ? 1 : $this->cache->increment($stem.':n');

        if (is_int($slot) && $slot > 0) {
            $this->cache->put($stem.':n:'.$slot, $key, self::TTL);
        }
    }

    /**
     * The value keys of every counter in a minute, mapped to counter names.
     *
     * @return array<string, string>
     */
    private function valueKeys(string $instance, string $minute): array
    {
        $stem = Keys::bucket($instance, $minute);
        $slots = (int) $this->cache->get($stem.':n', 0);

        if ($slots < 1) {
            return [];
        }

        $slotKeys = array_map(fn (int $slot): string => $stem.':n:'.$slot, range(1, $slots));
        $valueKeys = [];

        foreach ($this->cache->getMultiple($slotKeys) as $name) {
            if (is_string($name) && $name !== '') {
                $valueKeys[$this->valueKey($instance, $minute, $name)] = $name;
            }
        }

        return $valueKeys;
    }
}
