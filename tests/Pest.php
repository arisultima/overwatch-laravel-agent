<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Tests\TestCase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(TestCase::class)->in('Feature');

/**
 * Whether a Redis server answers at REDIS_HOST and the phpredis extension is
 * loaded. Redis tests skip when it does not.
 */
function redisReachable(): bool
{
    static $reachable = null;

    if ($reachable !== null) {
        return $reachable;
    }

    if (! extension_loaded('redis')) {
        return $reachable = false;
    }

    $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('REDIS_PORT') ?: 6379);

    $socket = @fsockopen($host, $port, $errno, $error, 0.5);

    if ($socket === false) {
        return $reachable = false;
    }

    fclose($socket);

    return $reachable = true;
}

/**
 * Point the overwatch store at Redis under a prefix of its own, so the test
 * touches only its own keys. Returns the prefix.
 */
function useRedisCache(): string
{
    $prefix = 'overwatch-test-'.Str::lower(Str::random(10)).'-';

    config([
        'database.redis.options.prefix' => '',
        'cache.prefix' => $prefix,
        'overwatch.cache_store' => 'redis',
    ]);

    return $prefix;
}

/**
 * Every Redis key under the test prefix, with its value.
 *
 * @return array<string, mixed>
 */
function redisDump(string $prefix): array
{
    $redis = Redis::connection('cache');
    $dump = [];

    foreach ((array) $redis->command('keys', [$prefix.'*']) as $key) {
        $type = $redis->command('type', [$key]);

        $dump[$key] = in_array($type, [\Redis::REDIS_HASH, 'hash'], true)
            ? $redis->command('hgetall', [$key])
            : $redis->command('get', [$key]);
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

    foreach ((array) $redis->command('keys', [$prefix.'*']) as $key) {
        $redis->command('del', [$key]);
    }
}
