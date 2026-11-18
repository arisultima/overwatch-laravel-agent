<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\CacheBuckets;
use Arisultima\OverwatchAgent\Recording\ErrorDetails;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Recording\RedisBuckets;
use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Minute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

dataset('drivers', ['array store' => 'cache', 'redis' => 'redis']);

it('counts into the current minute of this instance', function (string $driver): void {
    $this->useDriver($driver);
    config(['overwatch.instance' => 'web-'.Str::random(6)]);

    app(Recorder::class)->count('http.requests');
    app(Recorder::class)->count('http.requests');

    expect(app(Buckets::class)->read(Instance::name(), [Minute::current()]))
        ->toBe([Minute::current() => ['http.requests' => 2]]);
})->with('drivers');

it('keeps two instances apart', function (string $driver): void {
    $this->useDriver($driver);
    $buckets = app(Buckets::class);
    $minute = Minute::current();

    $buckets->increment('web-1', $minute, 'http.requests');
    $buckets->increment('web-2', $minute, 'http.requests', 5);
    $buckets->increment('web-2', $minute, 'http.5xx');

    expect($buckets->read('web-1', [$minute]))->toBe([$minute => ['http.requests' => 1]])
        ->and($buckets->read('web-2', [$minute]))->toBe([$minute => ['http.5xx' => 1, 'http.requests' => 5]]);
})->with('drivers');

it('picks the Redis drivers only for a redis store', function (string $driver): void {
    $this->useDriver($driver);

    expect(app(Buckets::class))->toBeInstanceOf($driver === 'redis' ? RedisBuckets::class : CacheBuckets::class);
})->with('drivers');

it('reads several minutes and forgets them', function (string $driver): void {
    $this->useDriver($driver);
    $buckets = app(Buckets::class);

    $buckets->increment('web-1', '202611181305', 'http.requests', 3);
    $buckets->increment('web-1', '202611181306', 'http.requests');
    $buckets->increment('web-1', '202611181306', 'queue.mail.failed');

    expect($buckets->read('web-1', ['202611181305', '202611181306', '202611181307']))->toBe([
        '202611181305' => ['http.requests' => 3],
        '202611181306' => ['http.requests' => 1, 'queue.mail.failed' => 1],
        '202611181307' => [],
    ]);

    $buckets->forget('web-1', ['202611181305']);

    expect($buckets->read('web-1', ['202611181305', '202611181306']))->toBe([
        '202611181305' => [],
        '202611181306' => ['http.requests' => 1, 'queue.mail.failed' => 1],
    ]);
})->with('drivers');

it('keeps the largest value for a max key', function (string $driver): void {
    $this->useDriver($driver);
    $buckets = app(Buckets::class);

    foreach ([120, 1830, 40] as $ms) {
        $buckets->max('web-1', '202611181305', 'queue.mail.slowest_ms', $ms);
    }

    expect($buckets->read('web-1', ['202611181305']))
        ->toBe(['202611181305' => ['queue.mail.slowest_ms' => 1830]]);
})->with('drivers');

it('records queues, histogram bands and error groups', function (string $driver): void {
    $this->useDriver($driver);
    config(['overwatch.instance' => 'worker-1']);
    $recorder = app(Recorder::class);
    $fingerprint = hash('sha256', 'RuntimeException|app/Jobs/Send.php:12');

    $recorder->queue('mail', false, 300);
    $recorder->queue('mail', true, 900);
    $recorder->histogram(50);
    $recorder->histogram(51);
    $recorder->histogram(7200);
    $recorder->error($fingerprint, 'RuntimeException', 'app/Jobs/Send.php:12');
    $recorder->error($fingerprint, 'RuntimeException', 'app/Jobs/Send.php:12');

    expect(app(Buckets::class)->read('worker-1', [Minute::current()])[Minute::current()])->toEqualCanonicalizing([
        'queue.mail.processed' => 1,
        'queue.mail.failed' => 1,
        'queue.mail.slowest_ms' => 900,
        'histogram.le_50' => 1,
        'histogram.le_100' => 1,
        'histogram.gt_5000' => 1,
        'error.'.$fingerprint => 2,
    ])->and(app(ErrorDetails::class)->of([$fingerprint, str_repeat('0', 64)]))->toBe([
        $fingerprint => ['class' => 'RuntimeException', 'location' => 'app/Jobs/Send.php:12'],
    ]);
})->with('drivers');

it('sorts request durations into the schema bands', function (): void {
    config(['overwatch.instance' => 'web-1']);
    $recorder = app(Recorder::class);

    foreach ([0, 50, 100, 250, 500, 1000, 2500, 5000, 5001] as $ms) {
        $recorder->histogram($ms);
    }

    expect(app(Buckets::class)->read('web-1', [Minute::current()])[Minute::current()])->toEqualCanonicalizing([
        'histogram.le_50' => 2,
        'histogram.le_100' => 1,
        'histogram.le_250' => 1,
        'histogram.le_500' => 1,
        'histogram.le_1000' => 1,
        'histogram.le_2500' => 1,
        'histogram.le_5000' => 1,
        'histogram.gt_5000' => 1,
    ]);
});

it('names the instance from config or the hostname, lowercased and cut to 100 characters', function (): void {
    config(['overwatch.instance' => null]);

    expect(Instance::name())->toBe(mb_substr(mb_strtolower((string) gethostname()), 0, 100))
        ->and(Instance::hostname())->toBe((string) gethostname());

    config(['overwatch.instance' => str_repeat('a', 150)]);

    expect(Instance::name())->toBe(str_repeat('a', 100));
});

it('names minutes in UTC and lists the minutes between two, at most 60', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-18 21:05:59', 'Asia/Kuala_Lumpur'));

    expect(Minute::current())->toBe('202611181305')
        ->and(Minute::current(Carbon::parse('2026-11-18 13:59:00', 'UTC')))->toBe('202611181359')
        ->and(Minute::range('202611181259', '202611181302'))->toBe(['202611181300', '202611181301'])
        ->and(Minute::range('202611181300', '202611181301'))->toBe([])
        ->and(Minute::range('202611181302', '202611181300'))->toBe([])
        ->and(Minute::range('not-a-minute', '202611181300'))->toBe([]);

    $long = Minute::range('202611181000', '202611181300');

    expect($long)->toHaveCount(60)
        ->and($long[0])->toBe('202611181200')
        ->and($long[59])->toBe('202611181259');

    Carbon::setTestNow();
});
