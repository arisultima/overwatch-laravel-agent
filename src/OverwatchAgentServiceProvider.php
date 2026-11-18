<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent;

use Arisultima\OverwatchAgent\Http\RecordRequest;
use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\CacheBuckets;
use Arisultima\OverwatchAgent\Recording\DistinctSets;
use Arisultima\OverwatchAgent\Recording\ErrorDetails;
use Arisultima\OverwatchAgent\Recording\LockedDistinctSets;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Recording\RedisBuckets;
use Arisultima\OverwatchAgent\Recording\RedisDistinctSets;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

final class OverwatchAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/overwatch.php', 'overwatch');

        $this->registerStorage();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/overwatch.php' => config_path('overwatch.php'),
            ], 'overwatch-config');
        }

        // Without a token, or with reporting switched off, the package stays
        // inert: no listeners, no middleware, no schedule entry.
        if (! Config::enabled()) {
            return;
        }

        $this->registerRecorders();
        $this->registerMiddleware();
        $this->registerSchedule();
    }

    /**
     * The counter and set drivers, on the overwatch cache store: the Redis
     * drivers when that store is Redis, the cache drivers otherwise. Bound
     * lazily, so nothing touches the store until something is recorded.
     */
    private function registerStorage(): void
    {
        $this->app->singleton(Buckets::class, function (Container $app): Buckets {
            $cache = self::cache($app);
            $store = $cache->getStore();

            return $store instanceof RedisStore ? new RedisBuckets($store) : new CacheBuckets($cache);
        });

        $this->app->singleton(DistinctSets::class, function (Container $app): DistinctSets {
            $cache = self::cache($app);
            $store = $cache->getStore();
            $appKey = (string) $app->make('config')->get('app.key', '');

            return $store instanceof RedisStore
                ? new RedisDistinctSets($store, $appKey)
                : new LockedDistinctSets($cache, $appKey);
        });

        $this->app->singleton(ErrorDetails::class, fn (Container $app): ErrorDetails => new ErrorDetails(self::cache($app)));

        $this->app->singleton(Recorder::class);
    }

    private static function cache(Container $app): Repository
    {
        return $app->make('cache')->store(Config::store());
    }

    /**
     * Event listeners that count into the minute buckets.
     */
    private function registerRecorders(): void
    {
        //
    }

    /**
     * Pushed after the app's own global middleware is set, so the
     * ApplicationBuilder's setGlobalMiddleware() does not drop it.
     */
    private function registerMiddleware(): void
    {
        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            // Neither method is on the Kernel contract; a custom kernel may lack them.
            if (! method_exists($kernel, 'hasMiddleware') || ! method_exists($kernel, 'pushMiddleware')) {
                return;
            }

            if (! $kernel->hasMiddleware(RecordRequest::class)) {
                $kernel->pushMiddleware(RecordRequest::class);
            }
        });
    }

    /**
     * One report a minute. withoutOverlapping keeps a hung send from stacking
     * runs; onOneServer lets one node report for every machine that shares
     * the cache.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('overwatch:report')
                ->everyMinute()
                ->withoutOverlapping(5)
                ->onOneServer();
        });
    }
}
