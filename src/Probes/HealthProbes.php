<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes;

use Arisultima\OverwatchAgent\Config;
use Arisultima\OverwatchAgent\Probes\Failures\CacheNotReturned;
use Arisultima\OverwatchAgent\Probes\Failures\HorizonInactive;
use Arisultima\OverwatchAgent\Probes\Failures\StorageNotWritable;
use Arisultima\OverwatchAgent\Support\Fingerprint;
use Arisultima\OverwatchAgent\Support\Store;
use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * The report's `checks`: whether the app works from the inside, with the
 * field names of the app_health pull check so one evaluator reads both.
 *
 * Every probe is wrapped. A probe that throws reports `ok` false and the
 * short class name of what was thrown, never its message: a driver message
 * can carry a DSN, a path or a password. Each probe is one cheap call (a
 * select 1, a write and read, a stat, a ping, a size); the migration scan,
 * the one that reads directories, runs at most every 10 minutes.
 */
final class HealthProbes
{
    public const MIGRATIONS_KEY = 'overwatch:migrations';

    public const MIGRATIONS_SECONDS = 600;

    private const HORIZON = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function checks(): array
    {
        $checks = [
            'database' => self::attempt(self::database(...)),
            'cache' => self::attempt(self::cache(...)),
            'storage' => self::storage(),
            'migrations' => self::attempt(self::migrations(...)),
            'queue' => self::attempt(self::queue(...)),
            'scheduler' => ['ok' => true, 'last_run_seconds_ago' => 0, 'instrumented' => true],
        ];

        if (self::redisInUse()) {
            $checks['redis'] = self::attempt(self::redis(...));
        }

        if (interface_exists(self::HORIZON)) {
            $checks['horizon'] = self::attempt(self::horizon(...));
        }

        return $checks;
    }

    /**
     * Whether storage/app is writable, and the free space on its disk. Also
     * the `local.storage` of an instance stamp, so it is public.
     *
     * @return array<string, mixed>
     */
    public static function storage(): array
    {
        return self::attempt(static function (): array {
            $path = storage_path('app');

            if (! is_dir($path) || ! is_writable($path)) {
                throw new StorageNotWritable;
            }

            $free = @disk_free_space($path);

            return ['writable' => true, 'free_bytes' => $free === false ? null : (int) $free];
        });
    }

    /**
     * @return array{latency_ms: int}
     */
    private static function database(): array
    {
        $started = hrtime(true);

        app('db')->connection()->select('select 1');

        return ['latency_ms' => self::since($started)];
    }

    /**
     * A write and a read on the app's default store. A cache that accepts a
     * write and returns nothing is worse than one that is down: everything
     * appears to work and nothing is cached.
     *
     * @return array{}
     */
    private static function cache(): array
    {
        $cache = app('cache')->store();
        $key = 'overwatch:probe:'.Str::random(8);

        $cache->put($key, 'ok', 10);
        $read = $cache->get($key);
        $cache->forget($key);

        if ($read !== 'ok') {
            throw new CacheNotReturned;
        }

        return [];
    }

    /**
     * Migrations not yet run, from the app's directory and every path a
     * package registered (what `migrate` runs). Kept 10 minutes in the
     * overwatch store, as an array: a Redis store keeps a bare number raw
     * and returns it as a string. A store that fails costs a fresh scan, not
     * the check. The database is asked first, so a database that is down
     * fails the check before any directory is read.
     *
     * @return array{pending: int}
     */
    private static function migrations(): array
    {
        try {
            $cached = Store::cache()->get(self::MIGRATIONS_KEY);

            if (is_array($cached) && is_int($cached['pending'] ?? null)) {
                return ['pending' => $cached['pending']];
            }
        } catch (Throwable) {
            // The cache probe reports a broken store.
        }

        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $pending = count(array_diff(array_keys($files), $ran));

        try {
            Store::cache()->put(self::MIGRATIONS_KEY, ['pending' => $pending], self::MIGRATIONS_SECONDS);
        } catch (Throwable) {
            // Same: probed again next time.
        }

        return ['pending' => $pending];
    }

    /**
     * The default connection's default queue, and the failed jobs kept.
     *
     * @return array{driver: string, pending: ?int, oldest_pending_seconds: ?int, failed: ?int}
     */
    private static function queue(): array
    {
        return [
            'driver' => QueueDepths::driver(),
            ...QueueDepths::measure(Config::defaultQueue()),
            'failed' => self::failedJobs(),
        ];
    }

    /**
     * Every failed job the failer keeps; null when it cannot count them (an
     * app without a failed_jobs table has not lost its queue).
     */
    private static function failedJobs(): ?int
    {
        try {
            $failer = app('queue.failer');

            return $failer instanceof CountableFailedJobProvider ? (int) $failer->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{latency_ms: int}
     */
    private static function redis(): array
    {
        $started = hrtime(true);

        app('redis')->connection()->ping();

        return ['latency_ms' => self::since($started)];
    }

    /**
     * Running, paused or not running at all, as horizon:status reads it.
     *
     * @return array{supervisors: int, status: string}
     */
    private static function horizon(): array
    {
        $masters = app(self::HORIZON)->all();

        if ($masters === []) {
            throw new HorizonInactive;
        }

        $paused = false;

        foreach ($masters as $master) {
            $paused = $paused || ($master->status ?? null) === 'paused';
        }

        return ['supervisors' => count($masters), 'status' => $paused ? 'paused' : 'running'];
    }

    /**
     * A Redis connection is configured, its client is installed, and the
     * app keeps something on Redis. The stock database config names a Redis
     * connection in every app, so the setting alone would report Redis down
     * on every app that never used it.
     */
    private static function redisInUse(): bool
    {
        if (config('database.redis.default') === null) {
            return false;
        }

        $installed = match (config('database.redis.client', 'phpredis')) {
            'phpredis' => extension_loaded('redis'),
            'predis' => class_exists('Predis\Client'),
            default => false,
        };

        if (! $installed) {
            return false;
        }

        if (interface_exists(self::HORIZON)) {
            return true;
        }

        $defaultStore = config('cache.default');
        $drivers = [
            config('cache.stores.'.$defaultStore.'.driver'),
            config('cache.stores.'.(Config::store() ?? $defaultStore).'.driver'),
            config('session.driver'),
            QueueDepths::driver(),
        ];

        return in_array('redis', $drivers, true);
    }

    /**
     * Run one probe and turn whatever happens into the schema shape.
     *
     * @param  Closure(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private static function attempt(Closure $probe): array
    {
        try {
            return ['ok' => true, ...$probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename(Fingerprint::className($e))];
        }
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
