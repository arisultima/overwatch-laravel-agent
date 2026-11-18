<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Http\RecordRequest;
use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
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
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(5)
        ->and($event->onOneServer)->toBeTrue();
});

it('publishes the config', function (): void {
    @unlink(publishedConfigPath());

    $this->artisan('vendor:publish', ['--tag' => 'overwatch-config'])->assertExitCode(0);

    expect(file_exists(publishedConfigPath()))->toBeTrue()
        ->and(file_get_contents(publishedConfigPath()))
        ->toBe(file_get_contents(__DIR__.'/../../config/overwatch.php'));
});
