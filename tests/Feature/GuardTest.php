<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\Guard;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Support\Minute;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;

/**
 * A cache store that is down: every call throws, and each call is counted.
 */
final class DownStore implements Store
{
    public static int $calls = 0;

    public function get($key)
    {
        return $this->fail();
    }

    public function many(array $keys)
    {
        return $this->fail();
    }

    public function put($key, $value, $seconds)
    {
        return $this->fail();
    }

    public function putMany(array $values, $seconds)
    {
        return $this->fail();
    }

    public function increment($key, $value = 1)
    {
        return $this->fail();
    }

    public function decrement($key, $value = 1)
    {
        return $this->fail();
    }

    public function forever($key, $value)
    {
        return $this->fail();
    }

    public function forget($key)
    {
        return $this->fail();
    }

    public function flush()
    {
        return $this->fail();
    }

    public function getPrefix()
    {
        return '';
    }

    public function touch($key, $seconds)
    {
        return $this->fail();
    }

    private function fail(): never
    {
        self::$calls++;

        throw new RuntimeException('Connection refused');
    }
}

beforeEach(function (): void {
    DownStore::$calls = 0;

    Cache::extend('down', fn () => Cache::repository(new DownStore));

    config([
        'cache.stores.down' => ['driver' => 'down'],
        'overwatch.cache_store' => 'down',
    ]);
});

it('never throws when the cache store fails', function (): void {
    app(Recorder::class)->count('http.requests');

    expect(DownStore::$calls)->toBe(1)
        ->and(Guard::paused())->toBeTrue();

    // Within the 60 s pause, recording does not touch the store at all.
    app(Recorder::class)->count('http.requests');
    app(Recorder::class)->distinct('auth.login_failed_sources', '203.0.113.9');
    app(Recorder::class)->error(str_repeat('a', 64), 'RuntimeException', 'unknown');
    app(Recorder::class)->queue('default', true, 10);
    app(Recorder::class)->histogram(10);

    expect(DownStore::$calls)->toBe(1);
});

it('tries the store again after 60 seconds', function (): void {
    app(Recorder::class)->count('http.requests');

    $this->travel(59)->seconds();
    app(Recorder::class)->count('http.requests');

    expect(DownStore::$calls)->toBe(1);

    $this->travel(2)->seconds();
    app(Recorder::class)->count('http.requests');

    expect(DownStore::$calls)->toBe(2);
});

it('never throws from any recorder call on a failing store', function (string $call): void {
    $recorder = app(Recorder::class);

    match ($call) {
        'count' => $recorder->count('http.requests'),
        'distinct' => $recorder->distinct('auth.login_failed_sources', '203.0.113.9'),
        'error' => $recorder->error(str_repeat('a', 64), 'RuntimeException', 'unknown'),
        'queue' => $recorder->queue('default', false, 10),
        'histogram' => $recorder->histogram(10),
    };

    expect(Guard::paused())->toBeTrue();
})->with(['count', 'distinct', 'error', 'queue', 'histogram']);

it('swallows a store that cannot even be resolved', function (): void {
    config(['overwatch.cache_store' => 'missing-store']);

    app(Recorder::class)->count('http.requests');

    expect(Guard::paused())->toBeTrue();
});

it('records normally on a working store', function (): void {
    config(['overwatch.cache_store' => null, 'overwatch.instance' => 'web-1']);

    app(Recorder::class)->count('http.requests');

    expect(Guard::paused())->toBeFalse()
        ->and(app(Buckets::class)->read('web-1', [Minute::current()]))
        ->toBe([Minute::current() => ['http.requests' => 1]]);
});

it('runs the closure and swallows what it throws', function (): void {
    $ran = false;

    Guard::quietly(function () use (&$ran): void {
        $ran = true;
    });

    Guard::quietly(fn () => throw new TypeError('bad'));

    expect($ran)->toBeTrue()
        ->and(Guard::paused())->toBeTrue();
});
