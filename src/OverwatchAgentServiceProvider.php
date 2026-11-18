<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent;

use Arisultima\OverwatchAgent\Http\RecordRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

final class OverwatchAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/overwatch.php', 'overwatch');
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
