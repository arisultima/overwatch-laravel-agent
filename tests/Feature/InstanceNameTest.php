<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Recording\Keys;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Store;

it('lowercases the instance name and nothing else', function () {
    config(['overwatch.instance' => 'Web-1.Example']);

    expect(Instance::name())->toBe('web-1.example');

    as_instance('Web-1.Example');
    at('13:05:30');
    InstanceStamp::maybe();

    $instance = app(ReportBuilder::class)->build(at('13:06:30'))['report']['instances'][0];

    expect($instance['name'])->toBe('web-1.example')
        ->and($instance['hostname'])->toBe(Instance::hostname());
});

it('merges machines whose names differ only in case into one instance', function () {
    at('13:05:30');

    foreach (['WEB-1', 'web-1', 'Web-1'] as $name) {
        as_instance($name);
        InstanceStamp::maybe();
        app(Recorder::class)->count('http.requests');
    }

    $report = app(ReportBuilder::class)->build(at('13:06:30'))['report'];

    expect(array_column($report['instances'], 'name'))->toBe(['web-1'])
        ->and($report['instances'][0]['minutes'][0]['counters']['http.requests'])->toBe(3);
});

it('never lists two instances that differ only in case', function () {
    at('13:05:30');
    as_instance('web-1');
    InstanceStamp::maybe();

    // A stamp left by an older release, which kept the case.
    $cache = Store::cache();
    $cache->put(Keys::stamp('Web-1'), ['hostname' => 'web-1', 'stamped_at' => '2026-11-18T13:05:20Z', 'local' => null], 3700);
    $cache->put(Keys::stamp('WORKER-1'), ['hostname' => 'worker-1', 'stamped_at' => '2026-11-18T13:05:20Z', 'local' => null], 3700);
    $cache->forever(Keys::instances(), ['Web-1', 'WORKER-1', 'web-1']);

    $built = app(ReportBuilder::class)->build(at('13:06:30'));
    $names = array_column($built['report']['instances'], 'name');

    expect($names)->toBe(['WORKER-1', 'web-1'])
        ->and(array_keys($built['sent']))->toBe(['WORKER-1', 'web-1']);

    $lowered = array_map(mb_strtolower(...), $names);

    expect(array_unique($lowered))->toBe($lowered);
});
