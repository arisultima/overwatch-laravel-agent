<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent;

use Arisultima\OverwatchAgent\Http\RecordRequest;
use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\CacheBuckets;
use Arisultima\OverwatchAgent\Recording\DistinctSets;
use Arisultima\OverwatchAgent\Recording\ErrorDetails;
use Arisultima\OverwatchAgent\Recording\ExceptionRecorder;
use Arisultima\OverwatchAgent\Recording\LockedDistinctSets;
use Arisultima\OverwatchAgent\Recording\QueryRecorder;
use Arisultima\OverwatchAgent\Recording\QueueRecorder;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Recording\RedisBuckets;
use Arisultima\OverwatchAgent\Recording\RedisDistinctSets;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
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
     * Event listeners that count into the minute buckets. Each recorder is a
     * singleton resolved on its first event, and the listeners are closures
     * (a class-string listener is reflected on every dispatch). A query that
     * is not slow costs a container lookup and one comparison against the
     * threshold the recorder read once.
     */
    private function registerRecorders(): void
    {
        $app = $this->app;

        $app->singleton(QueryRecorder::class);
        $app->singleton(QueueRecorder::class);
        $app->singleton(ExceptionRecorder::class, fn (): ExceptionRecorder => new ExceptionRecorder(
            $app->make(Recorder::class),
            $app->basePath(),
        ));

        $events = $app->make('events');

        $events->listen(QueryExecuted::class, static fn (QueryExecuted $event) => $app->make(QueryRecorder::class)->handle($event));
        $events->listen(JobProcessing::class, static fn (JobProcessing $event) => $app->make(QueueRecorder::class)->processing($event));
        $events->listen(JobProcessed::class, static fn (JobProcessed $event) => $app->make(QueueRecorder::class)->processed($event));
        $events->listen(JobFailed::class, static fn (JobFailed $event) => $app->make(QueueRecorder::class)->failed($event));
        $events->listen(JobExceptionOccurred::class, static fn (JobExceptionOccurred $event) => $app->make(QueueRecorder::class)->exceptionOccurred($event));
        $events->listen(JobReleasedAfterException::class, static fn (JobReleasedAfterException $event) => $app->make(QueueRecorder::class)->released($event));

        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler) use ($app): void {
            ExceptionRecorder::attach($handler, static fn (): ExceptionRecorder => $app->make(ExceptionRecorder::class));
        });
    }

    /**
     * Pushed after the app's own global middleware is set, so the
     * ApplicationBuilder's setGlobalMiddleware() does not drop it.
     */
    private function registerMiddleware(): void
    {
        // One instance for handle() and terminate(), so the start it notes
        // is there when the request is counted.
        $this->app->singleton(RecordRequest::class);

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
