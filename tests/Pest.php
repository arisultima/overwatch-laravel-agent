<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Tests\TestCase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(TestCase::class)->in('Feature');

/**
 * Whether a Redis server answers at REDIS_HOST and the configured client
 * (phpredis or predis) is installed. Redis tests skip when it does not.
 */
function redisReachable(): bool
{
    static $reachable = [];

    $client = (string) config('database.redis.client', 'phpredis');

    if (isset($reachable[$client])) {
        return $reachable[$client];
    }

    $installed = match ($client) {
        'phpredis' => extension_loaded('redis'),
        'predis' => class_exists(\Predis\Client::class),
        default => false,
    };

    if (! $installed) {
        return $reachable[$client] = false;
    }

    $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('REDIS_PORT') ?: 6379);

    $socket = @fsockopen($host, $port, $errno, $error, 0.5);

    if ($socket === false) {
        return $reachable[$client] = false;
    }

    fclose($socket);

    return $reachable[$client] = true;
}

/**
 * Point the overwatch store at Redis under a cache prefix of its own, so the
 * test touches only its own keys, and with the given connection prefix (the
 * one the Redis client adds to every key). Returns the cache prefix.
 */
function useRedisCache(string $connectionPrefix = ''): string
{
    $prefix = 'overwatch-test-'.Str::lower(Str::random(10)).'-';

    config([
        'database.redis.options.prefix' => $connectionPrefix,
        'cache.prefix' => $prefix,
        'overwatch.cache_store' => 'redis',
    ]);

    return $prefix;
}

/**
 * The Redis keys under a cache prefix, as stored (with the connection
 * prefix), mapped to the name to pass back to the client (without it, since
 * the client adds it again).
 *
 * @return array<string, string>
 */
function redisTestKeys(string $prefix): array
{
    $connectionPrefix = (string) config('database.redis.options.prefix', '');
    $keys = [];

    // The client prefixes the KEYS pattern but returns keys as stored.
    foreach ((array) Redis::connection('cache')->command('keys', [$prefix.'*']) as $stored) {
        $keys[(string) $stored] = str_starts_with((string) $stored, $connectionPrefix)
            ? substr((string) $stored, strlen($connectionPrefix))
            : (string) $stored;
    }

    return $keys;
}

/**
 * Every Redis key under the test prefix, as stored, with its value.
 *
 * @return array<string, mixed>
 */
function redisDump(string $prefix): array
{
    $redis = Redis::connection('cache');
    $dump = [];

    foreach (redisTestKeys($prefix) as $stored => $key) {
        $type = $redis->command('type', [$key]);
        $hash = is_int($type) ? $type === \Redis::REDIS_HASH : (string) $type === 'hash';

        $dump[$stored] = $hash ? $redis->command('hgetall', [$key]) : $redis->command('get', [$key]);
    }

    return $dump;
}

/**
 * Delete every Redis key under the test prefix. Never a flush: the server
 * may hold other data.
 */
function forgetRedisKeys(string $prefix): void
{
    $redis = Redis::connection('cache');

    foreach (redisTestKeys($prefix) as $key) {
        $redis->command('del', [$key]);
    }
}

/**
 * This instance's counters in the current minute. Tests that read them
 * freeze time, so the minute cannot turn between recording and reading.
 *
 * @return array<string, int>
 */
function countedNow(): array
{
    $minute = \Arisultima\OverwatchAgent\Support\Minute::current();

    return app(\Arisultima\OverwatchAgent\Recording\Buckets::class)
        ->read(\Arisultima\OverwatchAgent\Support\Instance::name(), [$minute])[$minute];
}

/**
 * Whether any value on the array store holds this text, serialised or not.
 */
function arrayStoreHolds(string $needle): bool
{
    $store = \Illuminate\Support\Facades\Cache::store('array')->getStore();
    $storage = (new ReflectionProperty($store, 'storage'))->getValue($store);

    return str_contains(serialize($storage), $needle);
}

/**
 * The full example report in docs/protocol.md, decoded.
 *
 * @return array<string, mixed>
 */
function protocolExample(): array
{
    $doc = (string) file_get_contents(__DIR__.'/../docs/protocol.md');
    $section = substr($doc, (int) strpos($doc, '## Full example'));

    preg_match('/```json\n(.*?)\n```/s', $section, $match);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}
