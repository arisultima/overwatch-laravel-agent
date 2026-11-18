<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Support\Instance;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

/**
 * The stamp keys written to the cache while the callback runs.
 *
 * @return list<string>
 */
function stampWrites(Closure $fn): array
{
    $writes = [];

    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$writes) {
        if (str_starts_with($event->key, 'overwatch:stamp:')) {
            $writes[] = $event->key;
        }
    });

    $fn();

    return $writes;
}

it('stamps once a minute and lists the instance', function () {
    $this->freezeTime();

    $writes = stampWrites(function () {
        InstanceStamp::maybe();
        InstanceStamp::maybe();
    });

    expect($writes)->toHaveCount(1);

    $all = InstanceStamp::all();
    $name = Instance::name();

    expect($all)->toHaveKey($name)
        ->and($all[$name]['hostname'])->toBe(Instance::hostname())
        ->and($all[$name]['stamped_at'])->toBe(now()->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($all[$name]['quiet'])->toBeFalse()
        ->and($all[$name]['local']['storage']['ok'])->toBeTrue()
        ->and($all[$name]['local']['storage']['writable'])->toBeTrue()
        ->and($all[$name]['local']['php'])->toBe(PHP_VERSION);
});

it('stamps again once the minute has passed', function () {
    $this->freezeTime();
    InstanceStamp::maybe();

    $this->travel(59)->seconds();
    expect(stampWrites(fn () => InstanceStamp::maybe()))->toBeEmpty();

    $this->travel(2)->seconds();
    expect(stampWrites(fn () => InstanceStamp::maybe()))->toHaveCount(1)
        ->and(InstanceStamp::all()[Instance::name()]['stamped_at'])->toBe(now()->utc()->format('Y-m-d\TH:i:s\Z'));
});

it('costs no cache call once this process stamped within the minute', function () {
    $this->freezeTime();
    InstanceStamp::maybe();

    $calls = 0;
    Event::listen([RetrievingKey::class, KeyWritten::class, CacheMissed::class], function () use (&$calls) {
        $calls++;
    });

    InstanceStamp::maybe();

    expect($calls)->toBe(0);
});

it('reads the stamp once when a fresh process finds a recent one', function () {
    $this->freezeTime();
    InstanceStamp::maybe();

    // A new PHP-FPM request starts with empty statics.
    InstanceStamp::reset();
    $this->travel(30)->seconds();

    $reads = 0;
    Event::listen(RetrievingKey::class, function () use (&$reads) {
        $reads++;
    });

    expect(stampWrites(fn () => InstanceStamp::maybe()))->toBeEmpty()
        ->and($reads)->toBe(1);
});

it('marks an instance quiet after ten minutes and drops it after an hour', function () {
    $this->freezeTime();
    InstanceStamp::maybe();

    $this->travel(601)->seconds();
    expect(InstanceStamp::all()[Instance::name()]['quiet'])->toBeTrue();

    $this->travel(3100)->seconds();
    expect(InstanceStamp::all())->toBe([])
        ->and(cache()->store()->get('overwatch:instances'))->toBe([]);
});

it('lists every instance that shares the cache', function () {
    $this->freezeTime();

    config(['overwatch.instance' => 'web-1']);
    InstanceStamp::maybe();

    InstanceStamp::reset();
    config(['overwatch.instance' => 'worker-1']);
    InstanceStamp::maybe();

    expect(array_keys(InstanceStamp::all()))->toBe(['web-1', 'worker-1']);
});

it('stamps from a queue worker loop and when the app terminates', function () {
    $this->freezeTime();

    event(new Looping('sync', 'default'));
    expect(InstanceStamp::all())->toHaveKey(Instance::name());

    InstanceStamp::reset();
    cache()->store()->forget('overwatch:instances');
    cache()->store()->forget('overwatch:stamp:'.Instance::name());
    $this->travel(61)->seconds();

    $this->app->terminate();
    expect(InstanceStamp::all())->toHaveKey(Instance::name());
});

it('keeps a worker loop running', function () {
    // The worker stops when a Looping listener returns false.
    expect(Event::until(new Looping('sync', 'default')))->toBeNull()
        ->and(InstanceStamp::all())->toHaveKey(Instance::name());
});

it('never throws when the cache store fails', function () {
    config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nowhere'], 'overwatch.cache_store' => 'broken']);

    InstanceStamp::maybe();

    expect(InstanceStamp::all())->toBe([]);
});

it('does not stamp when reporting is disabled', function () {
    $this->bootWith(['token' => null]);
    $this->freezeTime();

    event(new Looping('sync', 'default'));
    $this->app->terminate();

    expect(cache()->store()->get('overwatch:instances'))->toBeNull();
});
