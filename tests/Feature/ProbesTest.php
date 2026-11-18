<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Probes\HealthProbes;
use Arisultima\OverwatchAgent\Probes\Hygiene;
use Arisultima\OverwatchAgent\Probes\QueueDepths;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An in-memory sqlite connection as the default, so the probes have a real
 * database to talk to.
 */
function useSqliteMemory(): void
{
    config([
        'database.connections.probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.default' => 'probe',
    ]);

    DB::purge('probe');
}

it('returns the health checks in the app_health shape', function () {
    useSqliteMemory();

    $checks = HealthProbes::checks();

    expect(array_keys($checks))->toContain('database', 'cache', 'storage', 'migrations', 'queue', 'scheduler');

    expect($checks['database']['ok'])->toBeTrue()
        ->and($checks['database']['latency_ms'])->toBeInt()
        ->and($checks['cache'])->toBe(['ok' => true])
        ->and($checks['storage']['ok'])->toBeTrue()
        ->and($checks['storage']['writable'])->toBeTrue()
        ->and($checks['storage'])->toHaveKey('free_bytes')
        ->and($checks['migrations']['ok'])->toBeTrue()
        ->and($checks['migrations']['pending'])->toBeInt()
        ->and($checks['queue']['ok'])->toBeTrue()
        ->and($checks['queue']['driver'])->toBe('sync')
        ->and(array_keys($checks['queue']))->toBe(['ok', 'driver', 'pending', 'oldest_pending_seconds', 'failed'])
        ->and($checks['queue']['pending'])->toBeNull()
        ->and($checks['queue']['oldest_pending_seconds'])->toBeNull()
        ->and($checks['scheduler'])->toBe(['ok' => true, 'last_run_seconds_ago' => 0, 'instrumented' => true]);
});

it('reports a broken probe by class name only', function () {
    config([
        'database.connections.broken' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/overwatch-'.Str::random(8).'.sqlite',
            'prefix' => '',
        ],
        'database.default' => 'broken',
    ]);

    DB::purge('broken');

    $database = HealthProbes::checks()['database'];

    expect($database['ok'])->toBeFalse()
        ->and($database)->not->toHaveKey('message')
        ->and(array_keys($database))->toBe(['ok', 'error'])
        ->and($database['error'])->toBeIn(['QueryException', 'PDOException', 'SQLiteDatabaseDoesNotExistException'])
        ->and($database['error'])->not->toContain('nonexistent');
});

it('caches the pending migrations count for ten minutes', function () {
    useSqliteMemory();
    $this->freezeTime();

    $first = HealthProbes::checks()['migrations'];

    // A broken database would fail a fresh probe; the cached count is used.
    config(['database.connections.probe.database' => '/nonexistent/x.sqlite']);
    DB::purge('probe');

    expect(HealthProbes::checks()['migrations'])->toBe($first);

    $this->travel(601)->seconds();

    expect(HealthProbes::checks()['migrations']['ok'])->toBeFalse();
});

it('reports a cache that does not return what it was given', function () {
    config(['cache.stores.void' => ['driver' => 'null'], 'cache.default' => 'void']);

    expect(HealthProbes::checks()['cache'])->toBe(['ok' => false, 'error' => 'CacheNotReturned']);
});

it('builds without Redis or Horizon', function () {
    useSqliteMemory();
    config(['database.redis.default' => null]);

    $checks = HealthProbes::checks();

    expect($checks)->not->toHaveKey('redis')
        ->and($checks)->not->toHaveKey('horizon')
        ->and(array_keys($checks))->toBe(['database', 'cache', 'storage', 'migrations', 'queue', 'scheduler']);
});

it('leaves out Redis when nothing in the app uses it', function () {
    useSqliteMemory();

    // The stock database config always names a Redis connection.
    expect(config('database.redis.default'))->not->toBeNull()
        ->and(HealthProbes::checks())->not->toHaveKey('redis');
});

it('probes Redis when the app caches on it', function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis is not reachable at REDIS_HOST.');
    }

    useSqliteMemory();
    config(['cache.default' => 'redis', 'cache.prefix' => 'overwatch-test-'.Str::lower(Str::random(10)).'-']);

    $checks = HealthProbes::checks();

    expect($checks['redis']['ok'])->toBeTrue()
        ->and($checks['redis']['latency_ms'])->toBeInt();
});

it('sizes the database queue and ages its oldest waiting job', function () {
    useSqliteMemory();
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config([
        'queue.default' => 'database',
        'queue.connections.database' => ['driver' => 'database', 'connection' => 'probe', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
        'overwatch.queues' => ['default', 'mail'],
    ]);

    $this->freezeTime();
    Queue::connection('database')->pushRaw('{}', 'default');
    Queue::connection('database')->pushRaw('{}', 'default');
    $this->travel(42)->seconds();

    expect(QueueDepths::rows())->toBe([
        'rows' => [
            ['connection' => 'database', 'name' => 'default', 'pending' => 2, 'oldest_pending_seconds' => 42],
            ['connection' => 'database', 'name' => 'mail', 'pending' => 0, 'oldest_pending_seconds' => null],
        ],
        'truncated' => 0,
    ]);

    $queue = HealthProbes::checks()['queue'];

    expect($queue['driver'])->toBe('database')
        ->and($queue['pending'])->toBe(2)
        ->and($queue['oldest_pending_seconds'])->toBe(42);
});

it('sizes a Redis queue and ages its oldest waiting job', function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis is not reachable at REDIS_HOST.');
    }

    $name = 'overwatch-test-'.Str::lower(Str::random(10));

    config([
        'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => $name, 'retry_after' => 90],
        'queue.default' => 'redis',
        'overwatch.queues' => [],
    ]);

    $this->beforeApplicationDestroyed(fn () => Redis::connection('default')->command('del', ['queues:'.$name, 'queues:'.$name.':notify']));

    $this->freezeTime();
    Queue::connection('redis')->push('SomeJob');
    $this->travel(7)->seconds();

    // Laravel 11 payloads carry no creation time, so the age is unknown there.
    $payload = json_decode((string) Redis::connection('default')->command('lindex', ['queues:'.$name, 0]), true);
    $age = isset($payload['createdAt']) ? 7 : null;

    expect(QueueDepths::rows()['rows'])->toBe([
        ['connection' => 'redis', 'name' => $name, 'pending' => 1, 'oldest_pending_seconds' => $age],
    ]);
});

it('gives null depths on a driver that cannot tell', function () {
    expect(QueueDepths::rows())->toBe([
        'rows' => [['connection' => 'sync', 'name' => 'default', 'pending' => null, 'oldest_pending_seconds' => null]],
        'truncated' => 0,
    ]);
});

it('caps the queue depths at 20 rows', function () {
    config(['overwatch.queues' => array_map(fn (int $i) => 'q'.$i, range(1, 25))]);

    $depths = QueueDepths::rows();

    expect($depths['rows'])->toHaveCount(20)
        ->and($depths['truncated'])->toBe(5)
        ->and($depths['rows'][19]['name'])->toBe('q20');
});

it('flags debug in production', function () {
    config(['app.env' => 'production', 'app.debug' => true]);

    $flags = Hygiene::flags();

    expect(array_keys($flags))->toBe([
        'debug_in_production', 'https', 'secure_session_cookie', 'maintenance_mode',
        'config_cached', 'routes_cached', 'app_key_set',
    ])
        ->and($flags['debug_in_production'])->toBeTrue()
        ->and($flags['app_key_set'])->toBeTrue();

    foreach ($flags as $flag) {
        expect($flag)->toBeBool();
    }
});

it('reads the other hygiene flags from the config', function () {
    config([
        'app.env' => 'local',
        'app.debug' => true,
        'app.url' => 'HTTPS://example.test',
        'session.secure' => true,
        'app.key' => '',
    ]);

    expect(Hygiene::flags())->toMatchArray([
        'debug_in_production' => false,
        'https' => true,
        'secure_session_cookie' => true,
        'maintenance_mode' => false,
        'app_key_set' => false,
    ]);

    config(['app.url' => 'http://example.test', 'session.secure' => null]);

    expect(Hygiene::flags())->toMatchArray(['https' => false, 'secure_session_cookie' => false]);
});
