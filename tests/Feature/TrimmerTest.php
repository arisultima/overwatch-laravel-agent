<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Report\Trimmer;

/**
 * One minute in the schema shape, every counter busy so it is large.
 *
 * @return array<string, mixed>
 */
function fixtureMinute(string $at, int $seed): array
{
    $counters = [];

    foreach (protocolExample()['instances'][0]['minutes'][0]['counters'] as $key => $ignored) {
        $counters[$key] = 100000 + $seed;
    }

    return [
        'at' => $at,
        'counters' => $counters,
        'histogram' => array_fill_keys(['le_50', 'le_100', 'le_250', 'le_500', 'le_1000', 'le_2500', 'le_5000', 'gt_5000'], 1000 + $seed),
        'distinct' => array_fill_keys([
            'auth.login_failed_accounts',
            'auth.login_failed_sources',
            'auth.login_failed_max_per_source',
            'auth.login_failed_max_per_account',
            'auth.login_accounts',
        ], 10 + $seed % 10),
    ];
}

/**
 * @return array<string, mixed>
 */
function fixtureError(int $i, int $count): array
{
    return [
        'fingerprint' => hash('sha256', 'e'.$i),
        'class' => 'App\Exceptions\SomethingFailed',
        'location' => 'app/Services/Thing'.$i.'.php:'.$i,
        'count' => $count,
    ];
}

/**
 * A report of the protocol's shape: $instances instances with $minutes
 * minutes each (the oldest at 12:00, or with $stagger at 12:00 to 12:09 by
 * instance), $errors error groups each, and $queues queue depth rows.
 *
 * @return array<string, mixed>
 */
function fixtureReport(int $instances, int $minutes, int $errors = 0, int $queues = 1, bool $stagger = false): array
{
    $report = protocolExample();
    $template = $report['instances'][0];
    $report['instances'] = [];

    foreach (range(1, $instances) as $n) {
        $instance = [...$template, 'name' => 'web-'.$n, 'hostname' => 'web-'.$n, 'queues' => []];
        $instance['minutes'] = [];

        foreach (range(0, $minutes - 1) as $m) {
            $offset = $m + ($stagger ? ($n - 1) % 10 : 0);
            $at = sprintf('2026-11-18T%02d:%02d:00Z', 12 + intdiv($offset, 60), $offset % 60);
            $instance['minutes'][] = fixtureMinute($at, $n * 100 + $m);
        }

        $instance['errors'] = $errors === 0 ? [] : array_map(fn (int $i) => fixtureError($i, 1000 - $i), range(1, $errors));
        $report['instances'][] = $instance;
    }

    $report['queue_depths'] = array_map(
        fn (int $i) => ['connection' => 'redis', 'name' => 'queue-'.$i, 'pending' => $i, 'oldest_pending_seconds' => $i],
        range(1, $queues),
    );

    return $report;
}

it('leaves a report within the caps as it is', function () {
    $report = fixtureReport(2, 3, 2);

    expect(Trimmer::fit($report))->toBe($report);
});

it('caps error groups and queue depths at 20 and counts what it cut', function () {
    $report = fixtureReport(1, 1, 25, 23);
    $report['queue_depths_truncated'] = 2;

    $fit = Trimmer::fit($report);

    expect($fit['instances'][0]['errors'])->toHaveCount(20)
        ->and($fit['instances'][0]['errors'][0])->toBe($report['instances'][0]['errors'][0])
        ->and($fit['instances'][0]['errors'][19])->toBe($report['instances'][0]['errors'][19])
        ->and($fit['instances'][0]['errors_truncated'])->toBe(5)
        ->and($fit['queue_depths'])->toHaveCount(20)
        ->and($fit['queue_depths_truncated'])->toBe(2 + 3)
        ->and($fit['dropped_minutes'])->toBe(0);
});

it('caps an instance at 60 minutes and 20 queues', function () {
    $report = fixtureReport(1, 62);
    $report['instances'][0]['queues'] = array_map(
        fn (int $i) => ['name' => 'q'.$i, 'processed' => 100 - $i, 'failed' => 0, 'slowest_ms' => 5],
        range(1, 22),
    );

    $fit = Trimmer::fit($report);

    expect($fit['instances'][0]['minutes'])->toHaveCount(60)
        ->and($fit['instances'][0]['minutes'][0]['at'])->toBe('2026-11-18T12:02:00Z')
        ->and($fit['instances'][0]['minutes_truncated'])->toBe(2)
        ->and($fit['dropped_minutes'])->toBe(2)
        ->and(array_column($fit['instances'][0]['queues'], 'name'))->toBe(array_map(fn (int $i) => 'q'.$i, range(1, 20)));
});

it('trims a report over 64 KB in the schema order', function () {
    $report = fixtureReport(50, 60, 0, 25, stagger: true);
    $report['instances'][0]['errors'] = array_map(fn (int $i) => fixtureError($i, 1000 - $i), range(1, 25));
    $report['dropped_minutes'] = 4;

    expect(strlen(Trimmer::encode($report)))->toBeGreaterThan(Trimmer::REPORT_BYTES);

    $fit = Trimmer::fit($report);
    $truncated = array_sum(array_column($fit['instances'], 'minutes_truncated'));

    expect(strlen(Trimmer::encode($fit)))->toBeLessThanOrEqual(Trimmer::REPORT_BYTES)
        ->and($truncated)->toBeGreaterThan(0)
        ->and($fit['dropped_minutes'])->toBe(4 + $truncated)
        // Errors and queue depths were cut to their caps first.
        ->and($fit['instances'][0]['errors'])->toBe(array_slice($report['instances'][0]['errors'], 0, 20))
        ->and($fit['queue_depths'])->toHaveCount(20)
        ->and($fit['queue_depths_truncated'])->toBe(5);

    $kept = 0;

    foreach ($fit['instances'] as $i => $instance) {
        $count = count($instance['minutes']);
        $kept += $count;

        expect($instance['errors'])->toHaveCount($i === 0 ? 20 : 0)
            ->and($instance['errors_truncated'])->toBe($i === 0 ? 5 : 0)
            ->and($instance['minutes_truncated'])->toBe(60 - $count)
            // The oldest went: what is left is the newest, still oldest first.
            ->and($instance['minutes'])->toBe(array_slice($report['instances'][$i]['minutes'], 60 - $count));
    }

    expect($kept + $truncated)->toBe(50 * 60);

    // Oldest first across instances: no kept minute is older than a cut one.
    $oldestKept = min(array_map(fn (array $instance) => $instance['minutes'][0]['at'] ?? 'z', $fit['instances']));
    $newestCut = max(array_map(
        fn (int $i) => $fit['instances'][$i]['minutes_truncated'] > 0
            ? $report['instances'][$i]['minutes'][$fit['instances'][$i]['minutes_truncated'] - 1]['at']
            : '',
        array_keys($fit['instances']),
    ));

    expect($oldestKept >= $newestCut)->toBeTrue();
});

it('cuts the smallest error groups once no minutes are left', function () {
    $report = fixtureReport(50, 1, 20);

    $fit = Trimmer::fit($report);

    expect(strlen(Trimmer::encode($fit)))->toBeLessThanOrEqual(Trimmer::REPORT_BYTES)
        ->and(array_sum(array_column($fit['instances'], 'minutes_truncated')))->toBe(50);

    foreach ($fit['instances'] as $instance) {
        expect($instance['errors_truncated'])->toBe(20 - count($instance['errors']))
            ->and(array_column($instance['errors'], 'count'))->toBe(array_slice(range(999, 980), 0, count($instance['errors'])));
    }

    expect(array_sum(array_column($fit['instances'], 'errors_truncated')))->toBeGreaterThan(0);
});

it('caps the versions section on its own', function () {
    $report = fixtureReport(1, 1);
    $report['versions'] = [
        'lock_hash' => str_repeat('c', 64),
        'packages' => array_map(fn (int $i) => [sprintf('vendor-%s/package-%04d', str_repeat('x', 120), $i), 'v1.0.0', false], range(1, 1500)),
        'packages_truncated' => 0,
    ];

    expect(strlen(Trimmer::encode($report['versions'])))->toBeGreaterThan(Trimmer::VERSIONS_BYTES);

    $fit = Trimmer::fit($report);
    $kept = count($fit['versions']['packages']);

    expect(strlen(Trimmer::encode($fit['versions'])))->toBeLessThanOrEqual(Trimmer::VERSIONS_BYTES)
        ->and($fit['versions']['packages_truncated'])->toBe(1500 - $kept)
        ->and($fit['versions']['packages'])->toBe(array_slice($report['versions']['packages'], 0, $kept))
        ->and(strlen(Trimmer::encode($fit)))->toBeLessThanOrEqual(Trimmer::BODY_BYTES)
        // The versions section does not count against the report cap.
        ->and($fit['instances'][0]['minutes'])->toHaveCount(1);
});
