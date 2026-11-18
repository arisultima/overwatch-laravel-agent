<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Console\ReportCommand;
use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Probes\Versions;
use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\Keys;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Report\Progress;
use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Report\Sender;
use Arisultima\OverwatchAgent\Support\Store;
use Arisultima\OverwatchAgent\Tests\Fixtures\LockFailingStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What the fake Overwatch answers from now on: a status and a JSON body, or
 * null for a connection that fails.
 *
 * @param  array<string, mixed>  $body
 */
function overwatchAnswers(?int $status, array $body = ['accepted' => true]): void
{
    $GLOBALS['overwatch_answer'] = [$status, $body];
}

/**
 * How many times the fake Overwatch was called, failed connections included
 * (the client records only requests that got an answer).
 */
function overwatchCalls(): int
{
    return $GLOBALS['overwatch_calls'];
}

/**
 * Run the report command at a UTC moment on 2026-11-18, as the scheduler does.
 */
function reportAt(string $time): int
{
    at($time);

    return Artisan::call('overwatch:report');
}

/**
 * The report in the last request sent, decoded.
 *
 * @return array<string, mixed>
 */
function lastReport(): array
{
    /** @var Request $request */
    $request = Http::recorded()->last()[0];

    return json_decode((string) gzdecode($request->body()), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The counters of one instance in one minute still in the store.
 *
 * @return array<string, int>
 */
function bucketOf(string $instance, string $minute): array
{
    return app(Buckets::class)->read($instance, [$minute])[$minute];
}

/**
 * Every value on the array store, by key.
 *
 * @return array<string, mixed>
 */
function arrayStorage(): array
{
    $store = Cache::store('array')->getStore();
    $storage = (new ReflectionProperty($store, 'storage'))->getValue($store);

    return array_map(static fn (array $item): mixed => $item['value'], $storage);
}

beforeEach(function () {
    overwatchAnswers(202);
    $GLOBALS['overwatch_calls'] = 0;

    Http::fake(function () {
        $GLOBALS['overwatch_calls']++;
        [$status, $body] = $GLOBALS['overwatch_answer'];

        if ($status === null) {
            throw new ConnectionException('down');
        }

        return Http::response($body, $status);
    });
});

it('registers the command only while reporting is on', function () {
    expect(Artisan::all())->toHaveKey('overwatch:report')
        ->and(Artisan::all()['overwatch:report'])->toBeInstanceOf(ReportCommand::class);

    $this->bootWith(['token' => null]);

    expect(Artisan::all())->not->toHaveKey('overwatch:report');
});

it('marks minutes sent on 202', function () {
    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests', 3);
    Progress::addDropped(4);

    expect(reportAt('13:06:15'))->toBe(0);

    $report = lastReport();

    expect(minutesOf(instanceIn($report, 'web-1')))->toBe(['2026-11-18T13:05:00Z'])
        ->and(instanceIn($report, 'web-1')['minutes'][0]['counters']['http.requests'])->toBe(3)
        ->and($report['dropped_minutes'])->toBe(4)
        ->and($report['versions'])->toBeArray()
        ->and(Progress::lastSent('web-1'))->toBe('202611181305')
        ->and(bucketOf('web-1', '202611181305'))->toBe([])
        ->and(Progress::dropped())->toBe(0)
        ->and(Versions::due())->toBeFalse();

    // The next run covers nothing new, and does not send the minute again.
    expect(reportAt('13:06:40'))->toBe(0);

    expect(instanceIn(lastReport(), 'web-1')['minutes'])->toBe([])
        ->and(lastReport()['versions'])->toBeNull();

    Http::assertSentCount(2);
});

it('keeps minutes on 503 and on a timeout', function (?int $status) {
    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests', 3);
    Progress::addDropped(4);
    overwatchAnswers($status);

    expect(reportAt('13:06:15'))->toBe(0)
        ->and(overwatchCalls())->toBe(1)
        ->and(Progress::lastSent('web-1'))->toBeNull()
        ->and(bucketOf('web-1', '202611181305'))->toBe(['http.requests' => 3])
        ->and(Progress::dropped())->toBe(4)
        ->and(Versions::due())->toBeTrue();
})->with(['503' => 503, 'no connection' => null]);

it('gives up within the timeouts and keeps the minutes', function () {
    $options = app(Sender::class)->request()->getOptions();

    expect($options['timeout'])->toBe(5)
        ->and($options['connect_timeout'])->toBe(3);

    // A client of its own, without the fake set up for every test.
    Http::swap(new Factory(app('events')));
    Http::fake(fn () => throw new ConnectionException('down'));

    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests');

    expect(reportAt('13:06:15'))->toBe(0)
        ->and(overwatchCalls())->toBe(0)
        ->and(Progress::lastSent('web-1'))->toBeNull()
        ->and(bucketOf('web-1', '202611181305'))->toBe(['http.requests' => 1]);
});

it('sends kept minutes later and counts expired ones', function () {
    as_instance('web-1');

    foreach (['13:05:30', '13:06:30', '13:07:30'] as $time) {
        at($time);
        app(Recorder::class)->count('http.requests');
    }

    // Down at 13:06, back at 13:08: the kept minutes go then, none lost.
    overwatchAnswers(503);
    reportAt('13:06:15');
    overwatchAnswers(202);
    reportAt('13:08:15');

    $report = lastReport();

    expect(minutesOf(instanceIn($report, 'web-1')))->toBe(['2026-11-18T13:05:00Z', '2026-11-18T13:06:00Z', '2026-11-18T13:07:00Z'])
        ->and($report['dropped_minutes'])->toBe(0)
        ->and(Progress::lastSent('web-1'))->toBe('202611181307');

    at('13:08:30');
    app(Recorder::class)->count('http.requests');

    // Down for 70 minutes, failing every way it can.
    $time = \Illuminate\Support\Carbon::parse('2026-11-18 13:09:15', 'UTC');

    for ($i = 0; $i < 70; $i++) {
        overwatchAnswers([503, null, 429, 500, 401][$i % 5]);
        expect(reportAt($time->format('H:i:s')))->toBe(0);
        $time = $time->addMinute();
    }

    expect(Progress::lastSent('web-1'))->toBe('202611181307');

    overwatchAnswers(202);
    reportAt('14:19:15');

    $report = lastReport();

    // The newest 60 closed minutes are 13:19 to 14:18; 13:08 to 13:18 (11
    // minutes) passed out of reach unsent.
    expect($report['window'])->toBe(['from' => '2026-11-18T13:19:00Z', 'to' => '2026-11-18T14:19:00Z'])
        ->and($report['dropped_minutes'])->toBe(11)
        ->and(minutesOf(instanceIn($report, 'web-1')))->toBe([])
        ->and(Progress::lastSent('web-1'))->toBe('202611181418')
        ->and(Progress::dropped())->toBe(0);

    reportAt('14:20:15');

    expect(lastReport()['dropped_minutes'])->toBe(0);
});

it('marks sent and warns on 422', function (int $status) {
    Log::spy();
    as_instance('web-1');

    // Counts at 13:03 and 13:05, nothing at 13:04.
    foreach (['13:03:30', '13:05:30'] as $time) {
        at($time);
        app(Recorder::class)->count('http.requests');
    }

    Progress::addDropped(1);
    overwatchAnswers($status, [
        'message' => 'The instances field is required. (and 1 more error)',
        'errors' => ['instances' => ['The instances field is required.'], 'window' => ['Bad window.']],
    ]);

    expect(reportAt('13:06:15'))->toBe(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, (string) $status)
            && str_contains($message, 'The instances field is required.')
            && ! str_contains($message, 'Bad window.'),
    );

    // A resend cannot succeed, so the minutes are marked sent, and the
    // three refused closed minutes (the idle 13:04 among them) are lost:
    // the next report says so.
    expect(Progress::lastSent('web-1'))->toBe('202611181305')
        ->and(bucketOf('web-1', '202611181303'))->toBe([])
        ->and(bucketOf('web-1', '202611181305'))->toBe([])
        ->and(Versions::due())->toBeFalse()
        ->and(Progress::dropped())->toBe(1 + 3);

    overwatchAnswers(202);
    reportAt('13:07:15');

    expect(lastReport()['dropped_minutes'])->toBe(4)
        ->and(Progress::dropped())->toBe(0);
})->with(['422' => 422, '413' => 413]);

it('carries a refused report\'s own dropped minutes to the next one', function () {
    Log::spy();
    as_instance('web-1');
    Progress::markSent('web-1', '202611181200');
    at('13:29:30');
    app(Recorder::class)->count('http.requests');
    overwatchAnswers(422, ['message' => 'Invalid.']);

    reportAt('13:30:15');

    // 12:01 to 12:29 expired (29), and the 60 refused closed minutes 12:30
    // to 13:29, with counts or not.
    expect(lastReport()['dropped_minutes'])->toBe(29)
        ->and(Progress::dropped())->toBe(29 + 60);

    overwatchAnswers(202);
    reportAt('13:31:15');

    expect(lastReport()['dropped_minutes'])->toBe(89);
});

it('counts a refused report\'s trimmed minutes once', function () {
    Log::spy();
    at('13:10:30');

    // Ten instances with ten minutes each, every minute with eight error
    // groups: too big for 64 KB, so the trimmer cuts the oldest minutes and
    // counts them dropped.
    $buckets = app(Buckets::class);

    foreach (range(1, 10) as $i) {
        as_instance('web-'.$i);
        InstanceStamp::maybe();
        Progress::markSent('web-'.$i, '202611181300');

        foreach (range(1, 10) as $m) {
            $minute = '2026111813'.sprintf('%02d', $m);
            $buckets->increment('web-'.$i, $minute, 'http.requests');

            foreach (range(1, 8) as $e) {
                $buckets->increment('web-'.$i, $minute, 'error.'.hash('sha256', $i.'-'.$m.'-'.$e));
            }
        }
    }

    overwatchAnswers(422, ['message' => 'Invalid.']);
    reportAt('13:11:15');

    $report = lastReport();
    $truncated = array_sum(array_column($report['instances'], 'minutes_truncated'));

    // 13:01 to 13:10 for each of the ten instances, trimmed or not; nothing
    // expired, so the trimmed minutes the report counted are not added again.
    expect($truncated)->toBeGreaterThan(0)
        ->and($report['dropped_minutes'])->toBe($truncated)
        ->and(Progress::dropped())->toBe(10 * 10);
});

it('logs a refused token once an hour and keeps the minutes', function () {
    Log::spy();
    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests');
    overwatchAnswers(401, ['message' => 'Unauthenticated.']);

    reportAt('13:06:15');
    reportAt('13:07:15');
    reportAt('13:30:15');

    Log::shouldHaveReceived('warning')->once();

    expect(Progress::lastSent('web-1'))->toBeNull()
        ->and(bucketOf('web-1', '202611181305'))->toBe(['http.requests' => 1]);

    reportAt('14:07:20');

    Log::shouldHaveReceived('warning')->twice();

    expect(Progress::lastSent('web-1'))->toBeNull();
});

it('ignores the response body', function () {
    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests');
    reportAt('13:06:15');

    at('13:06:20');
    app(Recorder::class)->count('http.requests');
    overwatchAnswers(202, ['accepted' => true, 'interval_seconds' => 5, 'run' => 'rm -rf /', 'url' => 'https://elsewhere.test']);

    $config = config()->all();
    $before = arrayStorage();

    expect(reportAt('13:07:15'))->toBe(0);

    $after = arrayStorage();
    $changed = [];

    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
        if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
            $changed[] = $key;
        }
    }

    // Only the progress, the sent minute's counters (forgotten) and this
    // machine's stamp (a minute old) change.
    $unexpected = array_filter($changed, static fn (string $key): bool => $key !== Keys::progress('web-1')
        && $key !== Keys::stamp('web-1')
        && ! str_starts_with($key, Keys::bucket('web-1', '202611181306')));

    expect(config()->all())->toBe($config)
        ->and($changed)->toContain(Keys::progress('web-1'))
        ->and($unexpected)->toBe([])
        ->and(arrayStoreHolds('interval_seconds'))->toBeFalse()
        ->and(arrayStoreHolds('elsewhere.test'))->toBeFalse()
        ->and(Progress::lastSent('web-1'))->toBe('202611181306');

    reportAt('13:08:15');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://overwatch.test/api/agent/v1/app-report');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'elsewhere'));
});

it('marks each instance\'s own progress in a shared cache', function (string $driver) {
    $this->useDriver($driver);

    at('13:05:20');
    as_instance('web-1');
    app(Recorder::class)->count('http.requests', 4);
    InstanceStamp::maybe();
    at('13:06:20');
    app(Recorder::class)->count('http.requests', 1);

    as_instance('worker-1');
    at('13:05:30');
    app(Recorder::class)->count('jobs.processed', 2);
    InstanceStamp::maybe();

    expect(reportAt('13:06:30'))->toBe(0);

    $report = lastReport();

    expect($report['reporter'])->toBe('worker-1')
        ->and(minutesOf(instanceIn($report, 'web-1')))->toBe(['2026-11-18T13:05:00Z'])
        ->and(minutesOf(instanceIn($report, 'worker-1')))->toBe(['2026-11-18T13:05:00Z'])
        ->and(Progress::lastSent('web-1'))->toBe('202611181305')
        ->and(Progress::lastSent('worker-1'))->toBe('202611181305')
        ->and(bucketOf('web-1', '202611181305'))->toBe([])
        ->and(bucketOf('worker-1', '202611181305'))->toBe([])
        // The open minute is neither sent nor forgotten.
        ->and(bucketOf('web-1', '202611181306'))->toBe(['http.requests' => 1]);

    // Every minute goes once.
    reportAt('13:07:15');

    $report = lastReport();

    expect(minutesOf(instanceIn($report, 'web-1')))->toBe(['2026-11-18T13:06:00Z'])
        ->and(minutesOf(instanceIn($report, 'worker-1')))->toBe([]);
})->with(['array store' => 'cache', 'redis' => 'redis']);

it('marks an all-digit instance sent', function () {
    as_instance('101');
    at('13:05:30');
    app(Recorder::class)->count('http.requests');
    InstanceStamp::maybe();

    reportAt('13:06:15');

    expect(Progress::lastSent('101'))->toBe('202611181305')
        ->and(bucketOf('101', '202611181305'))->toBe([]);
});

it('sends nothing while another run holds the report lock', function (string $driver) {
    $this->useDriver($driver);

    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests');

    expect(ReportCommand::LOCK_SECONDS)->toBe(300);

    $other = Store::cache()->getStore()->lock(Keys::reportLock(), ReportCommand::LOCK_SECONDS);

    expect($other->get())->toBeTrue()
        ->and(reportAt('13:06:15'))->toBe(0);

    Http::assertNothingSent();

    expect(Progress::lastSent('web-1'))->toBeNull()
        ->and(bucketOf('web-1', '202611181305'))->toBe(['http.requests' => 1]);

    $other->release();

    expect(reportAt('13:06:20'))->toBe(0);

    Http::assertSentCount(1);

    // The run released its own lock.
    $mine = Store::cache()->getStore()->lock(Keys::reportLock(), ReportCommand::LOCK_SECONDS);

    expect($mine->get())->toBeTrue();

    $mine->release();
})->with(['array store' => 'cache', 'redis' => 'redis']);

it('never fails the scheduler', function (Closure $break) {
    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests');

    $break();

    expect(reportAt('13:06:15'))->toBe(0);
})->with([
    'the builder throws' => fn () => fn () => app()->bind(ReportBuilder::class, fn () => throw new RuntimeException('builder')),
    'the sender throws' => fn () => fn () => app()->bind(Sender::class, fn () => throw new RuntimeException('sender')),
    'the log throws' => fn () => fn () => [
        overwatchAnswers(422, ['message' => 'Invalid.']),
        Log::shouldReceive('warning')->andThrow(new RuntimeException('log')),
    ],
]);

it('still sends the checks when the store is down', function () {
    config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nowhere'], 'overwatch.cache_store' => 'broken']);
    as_instance('web-1');

    expect(reportAt('13:06:15'))->toBe(0);

    Http::assertSentCount(1);

    expect(lastReport()['instances'][0]['minutes'])->toBe([])
        ->and(lastReport()['checks'])->toHaveKey('cache');
});

it('sends but marks nothing when taking the lock throws', function (int $status) {
    Log::spy();
    Cache::extend('lock-failing', fn () => Cache::repository(new LockFailingStore));
    config(['cache.stores.lock-failing' => ['driver' => 'lock-failing'], 'overwatch.cache_store' => 'lock-failing']);

    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests', 2);
    Progress::addDropped(3);
    overwatchAnswers($status, ['message' => 'Invalid.']);

    $before = Store::cache()->getStore() instanceof LockFailingStore;

    expect($before)->toBeTrue()
        ->and(reportAt('13:06:15'))->toBe(0);

    // The health signal still goes, with the minute in it.
    expect(overwatchCalls())->toBe(1)
        ->and(minutesOf(instanceIn(lastReport(), 'web-1')))->toBe(['2026-11-18T13:05:00Z']);

    // Nothing is marked, forgotten, counted or cleared.
    expect(Progress::lastSent('web-1'))->toBeNull()
        ->and(bucketOf('web-1', '202611181305'))->toBe(['http.requests' => 2])
        ->and(Progress::dropped())->toBe(3)
        ->and(Versions::due())->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => $message === 'Overwatch agent: taking the report lock failed with RuntimeException.',
    )->once();
})->with(['202' => 202, '422' => 422]);

it('logs a swallowed failure by class only, once an hour per class', function () {
    Log::spy();
    as_instance('web-1');
    overwatchAnswers(null);

    reportAt('13:06:15');
    reportAt('13:07:15');
    reportAt('13:40:15');

    $sending = fn (string $message): bool => $message === 'Overwatch agent: sending the report failed with ConnectionException.';

    Log::shouldHaveReceived('warning')->withArgs($sending)->once();

    // Another class has its own hour.
    app()->bind(ReportBuilder::class, fn () => throw new LogicException('secret at /srv/app'));
    reportAt('13:41:15');
    reportAt('13:42:15');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => $message === 'Overwatch agent: the report run failed with LogicException.',
    )->once();

    Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'secret') || str_contains($message, 'down'))]);

    app()->offsetUnset(ReportBuilder::class);
    reportAt('14:07:20');

    Log::shouldHaveReceived('warning')->withArgs($sending)->twice();
});

it('keeps the hourly failure mark in the default store when the overwatch store fails', function () {
    Log::spy();
    config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nowhere'], 'overwatch.cache_store' => 'broken']);
    as_instance('web-1');
    overwatchAnswers(null);

    reportAt('13:06:15');
    reportAt('13:07:15');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => $message === 'Overwatch agent: sending the report failed with ConnectionException.',
    )->once();

    expect(Cache::store()->has(Keys::failureLogged('ConnectionException')))->toBeTrue();
});

it('never throws when every store and the log fail', function () {
    config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nowhere'], 'overwatch.cache_store' => 'broken']);
    Log::shouldReceive('warning')->andThrow(new RuntimeException('log'));
    as_instance('web-1');
    overwatchAnswers(null);

    expect(reportAt('13:06:15'))->toBe(0);
});
