<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;

/**
 * Buckets on a Redis cache store: one hash per instance-minute, one field per
 * counter. Each write is one round trip, a script that changes the field and
 * renews the TTL together.
 */
final class RedisBuckets implements Buckets
{
    private const INCREMENT = <<<'LUA'
redis.call('HINCRBY', KEYS[1], ARGV[1], ARGV[2])
redis.call('EXPIRE', KEYS[1], ARGV[3])
return 1
LUA;

    private const MAX = <<<'LUA'
local current = tonumber(redis.call('HGET', KEYS[1], ARGV[1]))
if current == nil or tonumber(ARGV[2]) > current then
  redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
end
redis.call('EXPIRE', KEYS[1], ARGV[3])
return 1
LUA;

    public function __construct(private readonly RedisStore $store) {}

    public function increment(string $instance, string $minute, string $key, int $by = 1): void
    {
        $this->connection()->eval(self::INCREMENT, 1, $this->key($instance, $minute), $key, $by, self::TTL);
    }

    public function max(string $instance, string $minute, string $key, int $value): void
    {
        $this->connection()->eval(self::MAX, 1, $this->key($instance, $minute), $key, $value, self::TTL);
    }

    public function read(string $instance, array $minutes): array
    {
        $read = [];

        foreach ($minutes as $minute) {
            $counters = [];
            $hash = $this->connection()->command('hgetall', [$this->key($instance, $minute)]);

            foreach (is_array($hash) ? $hash : [] as $field => $count) {
                if (is_numeric($count)) {
                    $counters[(string) $field] = (int) $count;
                }
            }

            ksort($counters);
            $read[$minute] = $counters;
        }

        return $read;
    }

    public function forget(string $instance, array $minutes): void
    {
        // One key per call, so a Redis cluster never sees a cross-slot delete.
        foreach ($minutes as $minute) {
            $this->connection()->command('del', [$this->key($instance, $minute)]);
        }
    }

    private function key(string $instance, string $minute): string
    {
        return $this->store->getPrefix().Keys::bucket($instance, $minute);
    }

    private function connection(): Connection
    {
        return $this->store->connection();
    }
}
