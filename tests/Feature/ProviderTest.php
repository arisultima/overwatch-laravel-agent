<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Http\RecordRequest;
use Arisultima\OverwatchAgent\Tests\Fixtures\DownStore;
use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * The scheduled overwatch:report entries.
 *
 * @return list<ScheduledEvent>
 */
function overwatchSchedule(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'overwatch:report'),
    ));
}

function publishedConfigPath(): string
{
    return config_path('overwatch.php');
}

afterEach(function (): void {
    @unlink(publishedConfigPath());
});

it('registers nothing without a token', function (): void {
    $this->bootWith(['token' => null]);

    expect(Event::hasListeners(Failed::class))->toBeFalse()
        ->and(app(HttpKernel::class)->hasMiddleware(RecordRequest::class))->toBeFalse()
        ->and(overwatchSchedule())->toBe([]);
});

it('registers nothing with a token but no url', function (): void {
    $this->bootWith(['url' => null]);

    expect(Event::hasListeners(Failed::class))->toBeFalse()
        ->and(app(HttpKernel::class)->hasMiddleware(RecordRequest::class))->toBeFalse()
        ->and(overwatchSchedule())->toBe([]);
});

it('registers nothing when disabled, even with a token', function (): void {
    $this->bootWith(['enabled' => false]);

    expect(Event::hasListeners(Failed::class))->toBeFalse()
        ->and(app(HttpKernel::class)->hasMiddleware(RecordRequest::class))->toBeFalse()
        ->and(overwatchSchedule())->toBe([]);
});

it('registers the middleware and the schedule with a token', function (): void {
    $this->bootWith(['token' => 'satapp_test', 'url' => 'https://overwatch.test']);

    expect(app(HttpKernel::class)->hasMiddleware(RecordRequest::class))->toBeTrue();

    $events = overwatchSchedule();

    expect($events)->toHaveCount(1);

    $event = $events[0];

    expect($event->command)->toContain('overwatch:report')
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeFalse()
        ->and($event->onOneServer)->toBeFalse();
});

it('leaves the rest of the schedule running when the default cache store is down', function (): void {
    // Laravel 12 and 13 read the pause flag from the default store at the
    // start of every schedule:run; an app with that store down turns the
    // polling off. Laravel 11 has no such read.
    $pausable = property_exists(Schedule::class, 'pausable') ? Schedule::$pausable : null;
    $interruptible = property_exists(Schedule::class, 'interruptible') ? Schedule::$interruptible : null;

    if ($pausable !== null) {
        Schedule::$pausable = false;
    }

    if ($interruptible !== null) {
        Schedule::$interruptible = false;
    }

    try {
        Cache::extend('down', fn () => Cache::repository(new DownStore));

        config([
            'cache.stores.down' => ['driver' => 'down'],
            'cache.default' => 'down',
            // The counters stay on a store that works.
            'overwatch.cache_store' => 'array',
        ]);
        app()->forgetInstance('cache.store');

        $schedule = app(Schedule::class);
        expect(overwatchSchedule())->toHaveCount(1);

        // Registered after the overwatch entry, so it runs after it.
        $ran = false;
        $schedule->call(function () use (&$ran): void {
            $ran = true;
        })->everyMinute();

        Artisan::call('schedule:run');

        expect($ran)->toBeTrue();
    } finally {
        if ($pausable !== null) {
            Schedule::$pausable = $pausable;
        }

        if ($interruptible !== null) {
            Schedule::$interruptible = $interruptible;
        }
    }
});

it('publishes the config', function (): void {
    @unlink(publishedConfigPath());

    $this->artisan('vendor:publish', ['--tag' => 'overwatch-config'])->assertExitCode(0);

    expect(file_exists(publishedConfigPath()))->toBeTrue()
        ->and(file_get_contents(publishedConfigPath()))
        ->toBe(file_get_contents(__DIR__.'/../../config/overwatch.php'));
});
