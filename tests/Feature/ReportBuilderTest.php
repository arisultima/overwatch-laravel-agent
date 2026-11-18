<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Report\Progress;
use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Report\Trimmer;
use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Package;
use Illuminate\Support\Carbon;

/**
 * Move the clock to a UTC moment on 2026-11-18, given as H:i:s.
 */
function at(string $time): Carbon
{
    test()->travelTo(Carbon::parse('2026-11-18 '.$time, 'UTC'));

    return Carbon::now();
}

/**
 * Record as this instance from now on, with a fresh process's statics.
 */
function as_instance(string $name): void
{
    config(['overwatch.instance' => $name]);
    InstanceStamp::reset();
}

/**
 * @return array{report: array<string, mixed>, sent: array<string, string>}
 */
function buildAt(string $time): array
{
    return app(ReportBuilder::class)->build(at($time));
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function instanceIn(array $report, string $name): array
{
    foreach ($report['instances'] as $instance) {
        if ($instance['name'] === $name) {
            return $instance;
        }
    }

    throw new RuntimeException('No instance '.$name.' in the report.');
}

/**
 * @param  array<string, mixed>  $instance
 * @return list<string>
 */
function minutesOf(array $instance): array
{
    return array_column($instance['minutes'], 'at');
}

it('builds a report that matches the protocol example keys', function () {
    $example = protocolExample();
    $exampleMinute = $example['instances'][0]['minutes'][0];

    as_instance('web-1');
    at('13:05:30');
    $recorder = app(Recorder::class);
    $recorder->count('http.requests');
    $recorder->count('auth.login_failed');
    $recorder->distinct('auth.login_failed_sources', '203.0.113.9');
    $recorder->histogram(42);
    $recorder->error(str_repeat('a', 64), 'RuntimeException', 'app/Thing.php:3');
    $recorder->queue('default', false, 120);
    InstanceStamp::maybe();

    $report = buildAt('13:06:30')['report'];

    expect(array_keys($report))->toBe(array_keys($example))
        ->and(array_keys($report['package']))->toBe(array_keys($example['package']))
        ->and(array_keys($report['window']))->toBe(array_keys($example['window']))
        ->and(array_keys($report['app']))->toBe(array_keys($example['app']))
        ->and(array_keys($report['hygiene']))->toBe(array_keys($example['hygiene']))
        ->and(array_diff(array_keys($report['checks']), array_keys($example['checks'])))->toBe([])
        ->and($report['instances'])->toHaveCount(1);

    $instance = $report['instances'][0];

    expect(array_keys($instance))->toBe(array_keys($example['instances'][0]))
        ->and(array_keys($instance['local']))->toBe(array_keys($example['instances'][0]['local']))
        ->and(array_keys($instance['errors'][0]))->toBe(array_keys($example['instances'][0]['errors'][0]))
        ->and(array_keys($instance['queues'][0]))->toBe(array_keys($example['instances'][1]['queues'][0]))
        ->and($instance['minutes'])->not->toBeEmpty();

    foreach ($instance['minutes'] as $minute) {
        expect(array_keys($minute))->toBe(array_keys($exampleMinute))
            ->and(array_keys($minute['counters']))->toBe(array_keys($exampleMinute['counters']))
            ->and(array_keys($minute['histogram']))->toBe(array_keys($exampleMinute['histogram']))
            ->and(array_keys($minute['distinct']))->toBe(array_keys($exampleMinute['distinct']));

        foreach ([...$minute['counters'], ...$minute['histogram'], ...$minute['distinct']] as $value) {
            expect($value)->toBeInt();
        }
    }
});

it('fills a minute from its counters, histogram, distinct sets, errors and queues', function () {
    as_instance('web-1');
    at('13:05:10');
    $recorder = app(Recorder::class);

    foreach (['203.0.113.9', '203.0.113.9', '203.0.113.9', '198.51.100.7'] as $address) {
        $recorder->count('auth.login_failed');
        $recorder->distinct('auth.login_failed_sources', $address);
    }

    $recorder->distinct('auth.login_failed_accounts', 'a@example.com');
    $recorder->distinct('auth.login_failed_accounts', 'a@example.com');
    $recorder->count('auth.login');
    $recorder->distinct('auth.login_accounts', 'App\Models\User|7');
    $recorder->count('http.requests', 3);
    $recorder->histogram(42);
    $recorder->histogram(6000);
    $recorder->count('some.future.key');
    $recorder->queue('mail.high', false, 120);
    $recorder->queue('mail.high', true, 1830);
    $recorder->error(str_repeat('b', 64), 'RuntimeException', 'app/Thing.php:3');

    at('13:06:20');
    $recorder->queue('mail.high', false, 400);
    $recorder->error(str_repeat('b', 64), 'RuntimeException', 'app/Thing.php:3');

    $built = buildAt('13:07:15');
    $instance = instanceIn($built['report'], 'web-1');
    $minute = $instance['minutes'][0];

    expect(minutesOf($instance))->toBe(['2026-11-18T13:05:00Z', '2026-11-18T13:06:00Z'])
        ->and($minute['counters']['http.requests'])->toBe(3)
        ->and($minute['counters']['auth.login_failed'])->toBe(4)
        ->and($minute['counters']['auth.login'])->toBe(1)
        ->and($minute['counters']['http.5xx'])->toBe(0)
        ->and($minute['counters'])->not->toHaveKey('some.future.key')
        ->and($minute['histogram']['le_50'])->toBe(1)
        ->and($minute['histogram']['gt_5000'])->toBe(1)
        ->and($minute['histogram']['le_100'])->toBe(0)
        ->and($minute['distinct'])->toBe([
            'auth.login_failed_accounts' => 1,
            'auth.login_failed_sources' => 2,
            'auth.login_failed_max_per_source' => 3,
            'auth.login_failed_max_per_account' => 2,
            'auth.login_accounts' => 1,
        ])
        ->and($instance['minutes'][1]['distinct'])->toBe(array_fill_keys(array_keys($minute['distinct']), 0))
        ->and($instance['queues'])->toBe([['name' => 'mail.high', 'processed' => 2, 'failed' => 1, 'slowest_ms' => 1830]])
        ->and($instance['errors'])->toBe([[
            'fingerprint' => str_repeat('b', 64),
            'class' => 'RuntimeException',
            'location' => 'app/Thing.php:3',
            'count' => 2,
        ]])
        ->and($built['sent'])->toBe(['web-1' => '202611181306']);
});

it('fills the top level from the probes', function () {
    as_instance('worker-1');
    at('13:05:10');
    InstanceStamp::maybe();

    $report = buildAt('13:07:15')['report'];

    expect($report['schema'])->toBe(1)
        ->and($report['package'])->toBe(['name' => 'arisultima/overwatch-laravel-agent', 'version' => Package::version()])
        ->and($report['sent_at'])->toBe('2026-11-18T13:07:15Z')
        ->and($report['reporter'])->toBe('worker-1')
        ->and($report['window'])->toBe(['from' => '2026-11-18T12:07:00Z', 'to' => '2026-11-18T13:07:00Z'])
        ->and($report['dropped_minutes'])->toBe(0)
        ->and($report['app'])->toBe([
            'environment' => config('app.env'),
            'debug' => false,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'timezone' => config('app.timezone'),
        ])
        ->and($report['checks']['database'])->toHaveKey('ok')
        ->and($report['checks']['scheduler'])->toBe(['ok' => true, 'last_run_seconds_ago' => 0, 'instrumented' => true])
        ->and($report['queue_depths'])->toHaveCount(1)
        ->and($report['queue_depths_truncated'])->toBe(0)
        ->and($report['hygiene'])->toHaveKey('app_key_set')
        ->and($report['versions'])->toBeArray()
        ->and($report['versions']['lock_hash'])->toMatch('/^[0-9a-f]{64}$/');

    $instance = $report['instances'][0];

    expect($instance['name'])->toBe('worker-1')
        ->and($instance['hostname'])->toBe(Instance::hostname())
        ->and($instance['stamped_at'])->toBe('2026-11-18T13:05:10Z')
        ->and($instance['quiet'])->toBeFalse()
        ->and($instance['local']['php'])->toBe(PHP_VERSION)
        ->and($instance['minutes'])->toBe([])
        ->and($instance['minutes_truncated'])->toBe(0)
        ->and($instance['errors_truncated'])->toBe(0);
});

it('sends versions only when they are due', function () {
    at('13:05:10');
    $first = buildAt('13:06:15')['report'];

    expect($first['versions'])->toBeArray();

    \Arisultima\OverwatchAgent\Probes\Versions::markSent($first['versions']['lock_hash']);

    expect(buildAt('13:07:15')['report']['versions'])->toBeNull();
});

it('never sends the open minute', function () {
    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests');

    // A new instance is covered from 60 minutes back, up to the minute
    // before the open one.
    $built = buildAt('13:05:50');
    expect(minutesOf(instanceIn($built['report'], 'web-1')))->toBe([])
        ->and($built['sent'])->toBe(['web-1' => '202611181304'])
        ->and($built['report']['window']['to'])->toBe('2026-11-18T13:05:00Z');

    // Within the grace after the minute ends, a late increment may still land.
    $built = buildAt('13:06:05');
    expect(minutesOf(instanceIn($built['report'], 'web-1')))->toBe([])
        ->and($built['sent'])->toBe(['web-1' => '202611181304'])
        ->and($built['report']['window']['to'])->toBe('2026-11-18T13:05:00Z');

    $built = buildAt('13:06:11');
    expect(minutesOf(instanceIn($built['report'], 'web-1')))->toBe(['2026-11-18T13:05:00Z'])
        ->and($built['sent'])->toBe(['web-1' => '202611181305'])
        ->and($built['report']['window']['to'])->toBe('2026-11-18T13:06:00Z')
        ->and(ReportBuilder::CLOSE_GRACE_SECONDS)->toBe(10);
});

it('collects every instance in a shared cache', function (string $driver) {
    $this->useDriver($driver);

    at('13:05:20');
    as_instance('web-1');
    app(Recorder::class)->count('http.requests', 4);
    InstanceStamp::maybe();

    as_instance('worker-1');
    app(Recorder::class)->count('jobs.processed', 2);
    InstanceStamp::maybe();

    $built = buildAt('13:06:30');

    expect(array_column($built['report']['instances'], 'name'))->toBe(['web-1', 'worker-1'])
        ->and(instanceIn($built['report'], 'web-1')['minutes'][0]['counters']['http.requests'])->toBe(4)
        ->and(instanceIn($built['report'], 'worker-1')['minutes'][0]['counters']['jobs.processed'])->toBe(2)
        ->and($built['report']['reporter'])->toBe('worker-1')
        ->and($built['sent'])->toBe(['web-1' => '202611181305', 'worker-1' => '202611181305']);
})->with(['array store' => 'cache', 'redis' => 'redis']);

it('does not resend a sent minute', function () {
    as_instance('web-1');
    at('13:05:20');
    app(Recorder::class)->count('http.requests');
    at('13:06:20');
    app(Recorder::class)->count('http.requests');

    Progress::markSent('web-1', '202611181305');

    $built = buildAt('13:07:15');

    expect(minutesOf(instanceIn($built['report'], 'web-1')))->toBe(['2026-11-18T13:06:00Z'])
        ->and($built['report']['window'])->toBe(['from' => '2026-11-18T13:06:00Z', 'to' => '2026-11-18T13:07:00Z'])
        ->and($built['sent'])->toBe(['web-1' => '202611181306']);

    Progress::markSent('web-1', '202611181306');

    $built = buildAt('13:07:15');

    expect(minutesOf(instanceIn($built['report'], 'web-1')))->toBe([])
        ->and($built['sent'])->toBe([])
        ->and($built['report']['window'])->toBe(['from' => '2026-11-18T13:07:00Z', 'to' => '2026-11-18T13:07:00Z']);
});

it('marks an idle instance sent up to the end of the window', function () {
    as_instance('web-1');
    Progress::markSent('web-1', '202611181303');

    $built = buildAt('13:07:15');

    expect(instanceIn($built['report'], 'web-1')['minutes'])->toBe([])
        ->and($built['sent'])->toBe(['web-1' => '202611181306'])
        ->and($built['report']['window']['from'])->toBe('2026-11-18T13:04:00Z');
});

it('caps errors at 20 groups', function () {
    as_instance('web-1');
    at('13:05:20');
    $recorder = app(Recorder::class);

    foreach (range(1, 25) as $i) {
        foreach (range(1, $i) as $ignored) {
            $recorder->error(hash('sha256', 'e'.$i), 'RuntimeException', 'app/E'.$i.'.php:1');
        }
    }

    $instance = instanceIn(buildAt('13:06:30')['report'], 'web-1');

    expect($instance['errors'])->toHaveCount(20)
        ->and($instance['errors_truncated'])->toBe(5)
        ->and(array_column($instance['errors'], 'count'))->toBe(range(25, 6))
        ->and($instance['errors'][0])->toBe([
            'fingerprint' => hash('sha256', 'e25'),
            'class' => 'RuntimeException',
            'location' => 'app/E25.php:1',
            'count' => 25,
        ]);
});

it('counts minutes that expired unsent into dropped_minutes', function () {
    as_instance('web-1');
    Progress::markSent('web-1', '202611181200');
    Progress::addDropped(2);

    // The newest 60 closed minutes are 12:30 to 13:29; 12:01 to 12:29 (29
    // minutes) are gone.
    $built = buildAt('13:30:15');

    expect($built['report']['dropped_minutes'])->toBe(2 + 29)
        ->and($built['report']['window'])->toBe(['from' => '2026-11-18T12:30:00Z', 'to' => '2026-11-18T13:30:00Z'])
        ->and($built['sent'])->toBe(['web-1' => '202611181329']);

    // Counted again, not added up, until a report is accepted.
    expect(buildAt('13:30:40')['report']['dropped_minutes'])->toBe(2 + 29);
});

it('does not count the minutes of an instance that was away while reports went out', function () {
    // worker-1 was last covered at 11:00 and then switched off; web-1's
    // reports were accepted all along. worker-1 counted nothing while away.
    Progress::markSent('worker-1', '202611181100');
    Progress::markSent('web-1', '202611181328');

    as_instance('worker-1');
    at('13:29:10');
    InstanceStamp::maybe();
    as_instance('web-1');
    InstanceStamp::maybe();

    $built = buildAt('13:30:15');

    expect($built['report']['dropped_minutes'])->toBe(0)
        ->and($built['sent'])->toBe(['worker-1' => '202611181329', 'web-1' => '202611181329']);
});

it('starts a new instance 60 minutes back without counting anything dropped', function () {
    as_instance('web-1');
    at('12:10:30');
    app(Recorder::class)->count('http.requests');
    at('13:00:30');
    app(Recorder::class)->count('http.requests');

    $built = buildAt('13:10:15');

    expect(minutesOf(instanceIn($built['report'], 'web-1')))->toBe(['2026-11-18T12:10:00Z', '2026-11-18T13:00:00Z'])
        ->and($built['report']['dropped_minutes'])->toBe(0)
        ->and($built['report']['window'])->toBe(['from' => '2026-11-18T12:10:00Z', 'to' => '2026-11-18T13:10:00Z'])
        ->and($built['sent'])->toBe(['web-1' => '202611181309']);
});

it('lists the reporter without a stamp, from its own probes', function () {
    as_instance('worker-1');

    $report = buildAt('13:06:30')['report'];

    expect($report['instances'])->toHaveCount(1)
        ->and($report['instances'][0]['name'])->toBe('worker-1')
        ->and($report['instances'][0]['stamped_at'])->toBeNull()
        ->and($report['instances'][0]['quiet'])->toBeFalse()
        ->and($report['instances'][0]['local'])->toBe(['storage' => $report['checks']['storage'], 'php' => PHP_VERSION]);
});

it('lists at most 50 instances, the reporter among them', function () {
    at('13:05:10');

    foreach (range(1, 55) as $i) {
        as_instance('web-'.$i);
        InstanceStamp::maybe();
    }

    as_instance('worker-1');
    $built = buildAt('13:06:30');
    $names = array_column($built['report']['instances'], 'name');

    expect($names)->toHaveCount(50)
        ->and($names[0])->toBe('web-1')
        ->and($names)->toContain('worker-1')
        ->and($names)->not->toContain('web-50')
        ->and($built['sent'])->toHaveCount(50);
});

it('still builds a valid report when the store fails', function () {
    config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nowhere'], 'overwatch.cache_store' => 'broken']);
    as_instance('worker-1');

    $built = buildAt('13:06:30');
    $report = $built['report'];

    expect($built['sent'])->toBe([])
        ->and($report['schema'])->toBe(1)
        ->and($report['instances'])->toHaveCount(1)
        ->and($report['instances'][0]['name'])->toBe('worker-1')
        ->and($report['instances'][0]['minutes'])->toBe([])
        ->and($report['dropped_minutes'])->toBe(0)
        ->and($report['versions'])->toBeNull()
        ->and(strlen(Trimmer::encode($report)))->toBeLessThan(Trimmer::REPORT_BYTES);
});

it('keeps progress without expiry and reads it back on Redis', function (string $driver) {
    $this->useDriver($driver);

    expect(Progress::lastSent('web-1'))->toBeNull()
        ->and(Progress::dropped())->toBe(0);

    Progress::markSent('web-1', '202611181305');
    Progress::addDropped(3);
    Progress::addDropped(4);

    $this->travel(30)->days();

    expect(Progress::lastSent('web-1'))->toBe('202611181305')
        ->and(Progress::lastSent('web-2'))->toBeNull()
        ->and(Progress::dropped())->toBe(7);

    Progress::clearDropped();

    expect(Progress::dropped())->toBe(0);
})->with(['array store' => 'cache', 'redis' => 'redis']);

it('takes an instance named only with digits', function () {
    at('13:05:20');
    as_instance('1001');
    app(Recorder::class)->count('http.requests');
    InstanceStamp::maybe();
    as_instance('web-1');
    InstanceStamp::maybe();

    $built = buildAt('13:06:30');

    expect(array_column($built['report']['instances'], 'name'))->toBe(['1001', 'web-1'])
        ->and(minutesOf(instanceIn($built['report'], '1001')))->toBe(['2026-11-18T13:05:00Z'])
        ->and($built['sent'])->toHaveKey('1001');
});

it('marks an all-digit instance sent and does not resend it', function () {
    at('13:05:20');
    as_instance('1001');
    app(Recorder::class)->count('http.requests');
    InstanceStamp::maybe();

    $built = buildAt('13:06:30');

    // PHP turns the name into an int key; marking must still work.
    expect(array_keys($built['sent']))->toBe([1001]);

    foreach ($built['sent'] as $instance => $minute) {
        Progress::markSent($instance, $minute);
    }

    expect(Progress::lastSent(1001))->toBe('202611181305')
        ->and(Progress::lastSent('1001'))->toBe('202611181305');

    at('13:06:40');
    app(Recorder::class)->count('http.requests');

    $built = buildAt('13:07:30');

    expect(minutesOf(instanceIn($built['report'], '1001')))->toBe(['2026-11-18T13:06:00Z']);
});

it('reports a set member that landed in the next minute', function () {
    as_instance('web-1');
    $recorder = app(Recorder::class);

    // The counter lands just before the turn of the minute, its set member
    // just after: 13:06 holds the member and no counter at all.
    at('13:05:59');
    $recorder->count('auth.login_failed');
    at('13:06:00');
    $recorder->distinct('auth.login_failed_sources', '203.0.113.9');

    // 13:07 has a counter, but not the one that goes with its set.
    at('13:07:30');
    $recorder->count('http.requests');
    $recorder->distinct('auth.login_accounts', 'App\Models\User|7');

    $instance = instanceIn(buildAt('13:08:30')['report'], 'web-1');

    expect(minutesOf($instance))->toBe(['2026-11-18T13:05:00Z', '2026-11-18T13:06:00Z', '2026-11-18T13:07:00Z'])
        ->and($instance['minutes'][0]['distinct']['auth.login_failed_sources'])->toBe(0)
        ->and($instance['minutes'][1]['counters'])->toBe(array_fill_keys(ReportBuilder::COUNTERS, 0))
        ->and($instance['minutes'][1]['distinct']['auth.login_failed_sources'])->toBe(1)
        ->and($instance['minutes'][1]['distinct']['auth.login_failed_max_per_source'])->toBe(1)
        ->and($instance['minutes'][2]['counters']['auth.login'])->toBe(0)
        ->and($instance['minutes'][2]['distinct']['auth.login_accounts'])->toBe(1);
});

it('adds expired and trimmed minutes together in dropped_minutes', function () {
    $names = ['web-1', 'web-2', 'web-3'];
    $buckets = app(\Arisultima\OverwatchAgent\Recording\Buckets::class);

    at('13:30:00');

    foreach ($names as $name) {
        as_instance($name);
        InstanceStamp::maybe();
        Progress::markSent($name, '202611181200');

        // Every one of the 60 readable minutes, 12:30 to 13:29, has counts.
        foreach (range(0, 59) as $m) {
            $buckets->increment($name, Carbon::parse('2026-11-18 12:30', 'UTC')->addMinutes($m)->format('YmdHi'), 'http.requests');
        }
    }

    $report = buildAt('13:30:15')['report'];
    $trimmed = array_sum(array_column($report['instances'], 'minutes_truncated'));

    // 12:01 to 12:29 expired for each of the three.
    expect($trimmed)->toBeGreaterThan(0)
        ->and($report['dropped_minutes'])->toBe(3 * 29 + $trimmed)
        ->and(strlen(Trimmer::encode([...$report, 'versions' => null])))->toBeLessThanOrEqual(Trimmer::REPORT_BYTES);
});
