<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\DistinctSets;
use Arisultima\OverwatchAgent\Recording\LockedDistinctSets;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Recording\RedisDistinctSets;
use Arisultima\OverwatchAgent\Support\Minute;
use Illuminate\Support\Facades\Cache;

dataset('set drivers', ['array store' => 'cache', 'redis' => 'redis']);

/**
 * Everything the array store holds, keys and values, as one string.
 */
function arrayStoreDump(): string
{
    // Read the storage directly: ArrayStore::all() is not on every supported Laravel.
    $store = Cache::store('array')->getStore();

    return serialize((fn (): array => $this->storage)->call($store));
}

it('stores only keyed hashes in distinct sets', function (string $driver): void {
    $prefix = $this->useDriver($driver);

    app(DistinctSets::class)->add('web-1', '202611181305', 'auth.login_failed_sources', '203.0.113.9');

    $dump = $driver === 'redis' ? serialize(redisDump($prefix)) : arrayStoreDump();
    $hash = hash_hmac('sha256', '203.0.113.9', (string) config('app.key'));

    expect($dump)->not->toContain('203.0.113.9')
        ->and($dump)->toContain($hash)
        ->and(app(DistinctSets::class)->sizes('web-1', '202611181305', 'auth.login_failed_sources'))
        ->toBe(['distinct' => 1, 'max' => 1, 'capped' => false]);
})->with('set drivers');

it('reports distinct and max per set', function (string $driver): void {
    $this->useDriver($driver);
    $sets = app(DistinctSets::class);

    foreach (['a', 'a', 'a', 'b'] as $identifier) {
        $sets->add('web-1', '202611181305', 'auth.login_failed_accounts', $identifier);
    }

    expect($sets->sizes('web-1', '202611181305', 'auth.login_failed_accounts'))
        ->toBe(['distinct' => 2, 'max' => 3, 'capped' => false])
        ->and($sets->sizes('web-1', '202611181305', 'auth.login_accounts'))
        ->toBe(['distinct' => 0, 'max' => 0, 'capped' => false])
        ->and($sets->sizes('web-2', '202611181305', 'auth.login_failed_accounts'))
        ->toBe(['distinct' => 0, 'max' => 0, 'capped' => false]);
})->with('set drivers');

it('caps a set at 1000 and says so', function (string $driver): void {
    $this->useDriver($driver);
    $sets = app(DistinctSets::class);

    for ($i = 0; $i < 1001; $i++) {
        $sets->add('web-1', '202611181305', 'auth.login_failed_sources', '198.51.100.'.$i);
    }

    // A known member still counts after the cap; a new one does not.
    $sets->add('web-1', '202611181305', 'auth.login_failed_sources', '198.51.100.0');

    expect($sets->sizes('web-1', '202611181305', 'auth.login_failed_sources'))
        ->toBe(['distinct' => 1000, 'max' => 2, 'capped' => true]);
})->with('set drivers');

it('picks the Redis set driver only for a redis store', function (string $driver): void {
    $this->useDriver($driver);

    expect(app(DistinctSets::class))
        ->toBeInstanceOf($driver === 'redis' ? RedisDistinctSets::class : LockedDistinctSets::class);
})->with('set drivers');

it('records distinct sets through the recorder under this instance and minute', function (): void {
    config(['overwatch.instance' => 'web-1']);

    app(Recorder::class)->distinct('auth.login_accounts', '42');
    app(Recorder::class)->distinct('auth.login_accounts', '42');

    expect(app(DistinctSets::class)->sizes('web-1', Minute::current(), 'auth.login_accounts'))
        ->toBe(['distinct' => 1, 'max' => 2, 'capped' => false]);
});

it('records no distinct set without an app key', function (): void {
    config(['app.key' => '']);

    app(DistinctSets::class)->add('web-1', '202611181305', 'auth.login_failed_sources', '203.0.113.9');

    expect(app(DistinctSets::class)->sizes('web-1', '202611181305', 'auth.login_failed_sources'))
        ->toBe(['distinct' => 0, 'max' => 0, 'capped' => false])
        ->and(arrayStoreDump())->not->toContain('203.0.113.9');
});

it('holds an attack minute on the locked driver', function (): void {
    $sets = app(DistinctSets::class);

    expect($sets)->toBeInstanceOf(LockedDistinctSets::class);

    $slowest = 0.0;

    // 1000 failed sign-ins from 500 addresses, each address twice.
    for ($i = 0; $i < 1000; $i++) {
        $started = hrtime(true);

        $sets->add('web-1', '202611181305', 'auth.login_failed_sources', '192.0.2.'.($i % 500));

        $slowest = max($slowest, (hrtime(true) - $started) / 1e9);
    }

    expect($sets->sizes('web-1', '202611181305', 'auth.login_failed_sources'))
        ->toBe(['distinct' => 500, 'max' => 2, 'capped' => false])
        ->and($slowest)->toBeLessThan(1.0);
});

it('drops one sample rather than wait past a second for a held lock', function (): void {
    $sets = app(DistinctSets::class);
    $sets->add('web-1', '202611181305', 'auth.login_failed_sources', '192.0.2.1');

    $lock = Cache::lock(LockedDistinctSets::lockKey('web-1', '202611181305', 'auth.login_failed_sources'), 10);
    $lock->get();

    $started = hrtime(true);
    $sets->add('web-1', '202611181305', 'auth.login_failed_sources', '192.0.2.2');
    $waited = (hrtime(true) - $started) / 1e9;

    $lock->release();

    expect($waited)->toBeLessThan(1.2)
        ->and($sets->sizes('web-1', '202611181305', 'auth.login_failed_sources'))
        ->toBe(['distinct' => 1, 'max' => 1, 'capped' => false]);
});

it('counts and sizes sets under a Redis connection prefix', function (): void {
    $connectionPrefix = 'overwatch-conn-'.strtolower(bin2hex(random_bytes(4))).'-';
    $prefix = $this->useDriver('redis', $connectionPrefix);
    $buckets = app(Buckets::class);
    $sets = app(DistinctSets::class);

    $buckets->increment('web-1', '202611181305', 'http.requests', 2);
    $buckets->max('web-1', '202611181305', 'queue.mail.slowest_ms', 900);

    for ($i = 0; $i < 1001; $i++) {
        $sets->add('web-1', '202611181305', 'auth.login_failed_sources', '198.51.100.'.$i);
    }

    $stored = array_keys(redisDump($prefix));

    expect($buckets->read('web-1', ['202611181305']))
        ->toBe(['202611181305' => ['http.requests' => 2, 'queue.mail.slowest_ms' => 900]])
        ->and($sets->sizes('web-1', '202611181305', 'auth.login_failed_sources'))
        ->toBe(['distinct' => 1000, 'max' => 1, 'capped' => true])
        ->and($stored)->toHaveCount(3)
        ->and(array_filter($stored, fn (string $key): bool => ! str_starts_with($key, $connectionPrefix.$prefix)))->toBe([]);

    $buckets->forget('web-1', ['202611181305']);

    expect($buckets->read('web-1', ['202611181305']))->toBe(['202611181305' => []]);
});
