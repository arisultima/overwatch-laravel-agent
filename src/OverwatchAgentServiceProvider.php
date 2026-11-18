<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent;

use Arisultima\OverwatchAgent\Console\ReportCommand;
use Arisultima\OverwatchAgent\Http\RecordRequest;
use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Recording\AuthRecorder;
use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\CacheBuckets;
use Arisultima\OverwatchAgent\Recording\DistinctSets;
use Arisultima\OverwatchAgent\Recording\ErrorDetails;
use Arisultima\OverwatchAgent\Recording\ExceptionRecorder;
use Arisultima\OverwatchAgent\Recording\LockedDistinctSets;
use Arisultima\OverwatchAgent\Recording\PrivilegeRecorder;
use Arisultima\OverwatchAgent\Recording\QueryRecorder;
use Arisultima\OverwatchAgent\Recording\QueueRecorder;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Recording\RedisBuckets;
use Arisultima\OverwatchAgent\Recording\RedisDistinctSets;
use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

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
        $this->registerStamps();
        $this->registerMiddleware();
        $this->registerCommand();
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

        $this->registerAuthRecorders($events);
        $this->registerPrivilegeRecorders($events);
    }

    /**
     * Sign-ins and failed sign-ins, lockouts, password resets and failed
     * two-factor challenges. Events that only some Laravel versions or
     * packages have are listened for only when their class exists; the
     * Overwatch hooks cover apps without them.
     */
    private function registerAuthRecorders(Dispatcher $events): void
    {
        $app = $this->app;

        $app->singleton(AuthRecorder::class);

        $auth = static fn (): AuthRecorder => $app->make(AuthRecorder::class);

        $events->listen(Failed::class, static fn (Failed $event) => $auth()->failed($event));
        $events->listen(Login::class, static fn (Login $event) => $auth()->login($event));

        $counted = [
            Lockout::class => 'auth.lockouts',
            PasswordReset::class => 'auth.password_reset',
            // Only in newer Laravel releases.
            'Illuminate\Auth\Events\PasswordResetLinkSent' => 'auth.password_reset_requested',
            'Laravel\Fortify\Events\TwoFactorAuthenticationFailed' => 'auth.two_factor_failed',
        ];

        foreach ($counted as $class => $key) {
            if (class_exists($class)) {
                $events->listen($class, static fn () => $auth()->count($key));
            }
        }
    }

    /**
     * Role and permission changes (spatie/laravel-permission, which raises
     * them only with its events_enabled option on), accounts created and
     * deleted on the user model, and authorisation checks denied.
     */
    private function registerPrivilegeRecorders(Dispatcher $events): void
    {
        $app = $this->app;

        $app->singleton(PrivilegeRecorder::class);

        $privilege = static fn (): PrivilegeRecorder => $app->make(PrivilegeRecorder::class);

        // Version 7 added the Event suffix; 6.15 to 6.x raise the same events
        // without it. Only one set exists in any install.
        $spatie = [
            'Spatie\Permission\Events\RoleAttachedEvent',
            'Spatie\Permission\Events\RoleDetachedEvent',
            'Spatie\Permission\Events\PermissionAttachedEvent',
            'Spatie\Permission\Events\PermissionDetachedEvent',
            'Spatie\Permission\Events\RoleAttached',
            'Spatie\Permission\Events\RoleDetached',
            'Spatie\Permission\Events\PermissionAttached',
            'Spatie\Permission\Events\PermissionDetached',
        ];

        foreach ($spatie as $class) {
            if (class_exists($class)) {
                $events->listen($class, static fn () => $privilege()->count('auth.privilege_changes'));
            }
        }

        // Listened for by event name, so the model is not booted here. The
        // name is the class's own spelling, as Eloquent names its events.
        $model = Config::userModel();

        if ($model !== null && class_exists($model) && is_subclass_of($model, Model::class)) {
            $model = (new ReflectionClass($model))->getName();

            $events->listen('eloquent.created: '.$model, static fn () => $privilege()->count('auth.accounts_created'));
            $events->listen('eloquent.deleted: '.$model, static fn (Model $user) => $privilege()->accountDeleted($user));
        }

        // The gate's final answer, after the app's own after callbacks, so
        // an ability one of them grants late is not counted as denied. A
        // listener cannot change the answer.
        $events->listen(GateEvaluated::class, static fn (GateEvaluated $event) => $privilege()->gateEvaluated($event));
    }

    /**
     * Each machine stamps its local probes about once a minute: when a
     * request or command ends, and on every queue worker loop (a worker may
     * serve no requests). The worker stops when a Looping listener returns
     * false, so this one returns nothing.
     */
    private function registerStamps(): void
    {
        $this->app->terminating(static function (): void {
            InstanceStamp::maybe();
        });

        $this->app->make('events')->listen(Looping::class, static function (): void {
            InstanceStamp::maybe();
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
     * The report command the schedule runs; console only.
     */
    private function registerCommand(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReportCommand::class]);
        }
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
