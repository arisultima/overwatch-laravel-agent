<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;

/**
 * Distinct sets on a Redis cache store: each set is a hash of keyed hash to
 * count. The cap is checked and the member added in one script, so no lock
 * is needed; a member refused at the cap sets a capped marker beside it.
 */
final class RedisDistinctSets implements DistinctSets
{
    private const ADD = <<<'LUA'
if redis.call('HEXISTS', KEYS[1], ARGV[1]) == 1 or redis.call('HLEN', KEYS[1]) < tonumber(ARGV[2]) then
  redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
  redis.call('EXPIRE', KEYS[1], ARGV[3])
else
  redis.call('SET', KEYS[2], '1', 'EX', ARGV[3])
end
return 1
LUA;

    public function __construct(
        private readonly RedisStore $store,
        private readonly string $appKey,
    ) {}

    public function add(string $instance, string $minute, string $set, string $identifier): void
    {
        // Without an app key the hash would be a plain digest, which a list
        // of every IPv4 address reverses. Better to record nothing.
        if ($this->appKey === '') {
            return;
        }

        $key = $this->key($instance, $minute, $set);

        $this->connection()->eval(
            self::ADD,
            2,
            $key,
            $key.':capped',
            hash_hmac('sha256', $identifier, $this->appKey),
            self::CAP,
            Buckets::TTL,
        );
    }

    public function sizes(string $instance, string $minute, string $set): array
    {
        $key = $this->key($instance, $minute, $set);
        $connection = $this->connection();

        $size = (int) $connection->command('hlen', [$key]);
        $counts = $connection->command('hvals', [$key]);
        $counts = is_array($counts) ? array_map('intval', $counts) : [];

        return [
            'distinct' => $size,
            'max' => $counts === [] ? 0 : max($counts),
            'capped' => (bool) $connection->command('exists', [$key.':capped']),
        ];
    }

    private function key(string $instance, string $minute, string $set): string
    {
        return $this->store->getPrefix().Keys::set($instance, $minute, $set);
    }

    private function connection(): Connection
    {
        return $this->store->connection();
    }
}
