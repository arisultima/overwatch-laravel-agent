<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Probes\Versions;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Report\TextLimits;

/**
 * Every text field the package fits, with its limit: a dotted path, `*`
 * for every row of a list.
 *
 * @return array<string, int>
 */
function textLimits(): array
{
    return [
        'package.name' => 255,
        'package.version' => 255,
        'reporter' => 100,
        'app.environment' => 64,
        'app.php' => 64,
        'app.laravel' => 64,
        'app.timezone' => 64,
        'checks.*.error' => 255,
        'checks.queue.driver' => 64,
        'checks.horizon.status' => 64,
        'queue_depths.*.connection' => 64,
        'queue_depths.*.name' => 255,
        'instances.*.name' => 100,
        'instances.*.hostname' => 255,
        'instances.*.local.php' => 64,
        'instances.*.local.storage.error' => 255,
        'instances.*.errors.*.class' => 512,
        'instances.*.errors.*.location' => 512,
        'instances.*.queues.*.name' => 255,
        'versions.packages.*.0' => 255,
        'versions.packages.*.1' => 255,
    ];
}

/**
 * The values at a dotted path with `*` wildcards, keyed by their full path.
 *
 * @return array<string, mixed>
 */
function valuesAt(mixed $data, string $path, string $at = ''): array
{
    if ($path === '') {
        return [$at => $data];
    }

    [$head, $rest] = array_pad(explode('.', $path, 2), 2, '');

    if (! is_array($data)) {
        return [];
    }

    $keys = $head === '*' ? array_keys($data) : (array_key_exists($head, $data) ? [$head] : []);
    $found = [];

    foreach ($keys as $key) {
        $found = [...$found, ...valuesAt($data[$key], $rest, ltrim($at.'.'.$key, '.'))];
    }

    return $found;
}

/**
 * What breaks the package's text limits in a report: a field that is not a
 * string, empty once trimmed, or longer than its limit.
 *
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function textViolations(array $report): array
{
    $violations = [];

    foreach (textLimits() as $path => $max) {
        foreach (valuesAt($report, $path) as $at => $value) {
            if (! is_string($value)) {
                $violations[] = $at.' is not a string';
            } elseif (trim($value) === '') {
                $violations[] = $at.' is empty';
            } elseif (mb_strlen($value) > $max) {
                $violations[] = $at.' has '.mb_strlen($value).' characters, over '.$max;
            }
        }
    }

    return $violations;
}

it('cuts every text field to its limit by characters and never sends one empty', function () {
    $report = [
        'package' => ['name' => str_repeat('p', 300), 'version' => ''],
        'reporter' => str_repeat('r', 150),
        'app' => ['environment' => str_repeat('é', 70), 'debug' => false, 'php' => '  ', 'laravel' => null, 'timezone' => ''],
        'checks' => [
            'database' => ['ok' => false, 'error' => str_repeat('E', 300)],
            'cache' => ['ok' => false, 'error' => ''],
            'queue' => ['ok' => true, 'driver' => str_repeat('d', 70), 'pending' => 1, 'oldest_pending_seconds' => null, 'failed' => 0],
            'horizon' => ['ok' => true, 'supervisors' => 1, 'status' => ''],
        ],
        'queue_depths' => [['connection' => '', 'name' => str_repeat('q', 300), 'pending' => 1, 'oldest_pending_seconds' => 1]],
        'instances' => [[
            'name' => str_repeat('n', 120),
            'hostname' => str_repeat('h', 300),
            'local' => ['storage' => ['ok' => false, 'error' => str_repeat('S', 300)], 'php' => ''],
            'errors' => [['fingerprint' => str_repeat('a', 64), 'class' => str_repeat('C', 600), 'location' => '', 'count' => 1]],
            'queues' => [['name' => str_repeat('w', 300), 'processed' => 1, 'failed' => 0, 'slowest_ms' => 3]],
        ]],
        'versions' => null,
    ];

    $fitted = TextLimits::apply($report);

    expect(textViolations($report))->not->toBe([])
        ->and(textViolations($fitted))->toBe([])
        ->and($fitted['app']['environment'])->toBe(str_repeat('é', 64))
        ->and($fitted['package']['version'])->toBe('unknown')
        ->and($fitted['app']['php'])->toBe('unknown')
        ->and($fitted['app']['laravel'])->toBe('unknown')
        ->and($fitted['checks']['cache']['error'])->toBe('unknown')
        ->and($fitted['instances'][0]['errors'][0]['location'])->toBe('unknown')
        ->and($fitted['instances'][0]['local']['php'])->toBe('unknown')
        // Everything else is left as it was.
        ->and($fitted['checks']['queue']['pending'])->toBe(1)
        ->and($fitted['instances'][0]['errors'][0]['fingerprint'])->toBe(str_repeat('a', 64))
        ->and($fitted['versions'])->toBeNull();
});

it('trims surrounding whitespace and cuts invalid UTF-8 safely', function () {
    $fitted = TextLimits::apply([
        'reporter' => '  web-1  ',
        'app' => ['environment' => "prod\xC3", 'php' => '8.4', 'laravel' => '13.0', 'timezone' => "\t UTC \n"],
    ]);

    expect($fitted['reporter'])->toBe('web-1')
        ->and($fitted['app']['timezone'])->toBe('UTC')
        ->and(mb_check_encoding($fitted['app']['environment'], 'UTF-8'))->toBeTrue()
        ->and(str_starts_with($fitted['app']['environment'], 'prod'))->toBeTrue();
});

it('builds a report within the text limits when the app has long and empty values', function () {
    config([
        'app.env' => str_repeat('é', 80),
        'app.timezone' => '',
        'overwatch.queues' => [str_repeat('q', 300), 'default'],
    ]);
    as_instance('web-1');
    at('13:05:30');

    $recorder = app(Recorder::class);
    $recorder->count('http.requests');
    $recorder->error(str_repeat('b', 64), str_repeat('C', 600), str_repeat('app/x/', 120).'y.php:1');
    $recorder->queue(str_repeat('w', 300), false, 10);
    InstanceStamp::maybe();

    $report = app(ReportBuilder::class)->build(at('13:06:30'))['report'];

    expect(textViolations($report))->toBe([])
        ->and($report['app']['environment'])->toBe(str_repeat('é', 64))
        ->and($report['app']['timezone'])->toBe('unknown')
        ->and(mb_strlen($report['queue_depths'][0]['name']))->toBe(255)
        ->and(mb_strlen($report['instances'][0]['errors'][0]['class']))->toBe(512)
        ->and(mb_strlen($report['instances'][0]['queues'][0]['name']))->toBe(255);
});

it('skips and counts packages whose name or version is past the text limits', function () {
    $section = Versions::fromRawData([[
        'root' => ['name' => 'acme/app'],
        'versions' => [
            'a/ok' => ['pretty_version' => 'v1.0.0'],
            'b/long' => ['pretty_version' => str_repeat('1', 256)],
            'c/empty' => ['pretty_version' => ' '],
            str_repeat('d', 256) => ['pretty_version' => 'v1.0.0'],
            'e/ok' => ['pretty_version' => ' v2.0.0 ', 'dev_requirement' => true],
        ],
    ]], str_repeat('f', 64));

    expect($section['packages'])->toBe([['a/ok', 'v1.0.0', false], ['e/ok', 'v2.0.0', true]])
        ->and($section['packages_truncated'])->toBe(3)
        ->and(textViolations(['versions' => $section]))->toBe([]);
});
