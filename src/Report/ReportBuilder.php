<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Report;

use Arisultima\OverwatchAgent\Probes\HealthProbes;
use Arisultima\OverwatchAgent\Probes\Hygiene;
use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Probes\QueueDepths;
use Arisultima\OverwatchAgent\Probes\Versions;
use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\DistinctSets;
use Arisultima\OverwatchAgent\Recording\ErrorDetails;
use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Minute;
use Arisultima\OverwatchAgent\Support\Package;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Builds one schema 1 report from every instance's closed minutes.
 *
 * Each instance listed (every machine that stamped within the hour, plus
 * this one) contributes the closed minutes after the last one sent for it,
 * at most the newest 60. A minute counts as closed only once it has been
 * over for CLOSE_GRACE_SECONDS, so an increment that lands just after the
 * turn of the minute is not lost. Minutes with nothing counted are left out
 * of `minutes` but still covered by the report, so an idle instance moves on
 * too. An instance whose progress or counters cannot be read is listed with
 * nothing and left out of `sent`, so its minutes wait for the next report.
 *
 * Nothing is marked here: the caller marks `sent` once the server has taken
 * the report. Building twice gives the same minutes.
 */
final class ReportBuilder
{
    public const SCHEMA = 1;

    /**
     * Seconds after a minute ends before it is reported.
     */
    public const CLOSE_GRACE_SECONDS = 10;

    public const MAX_INSTANCES = 50;

    /**
     * The minute `counters`, in the schema's order. Every one is sent, 0
     * when nothing was counted.
     */
    public const COUNTERS = [
        'http.requests', 'http.2xx', 'http.3xx', 'http.4xx', 'http.5xx',
        'http.401', 'http.403', 'http.419', 'http.429', 'http.slow',
        'db.slow_queries', 'jobs.processed', 'jobs.failed', 'errors.total',
        'auth.login', 'auth.login_privileged', 'auth.login_failed', 'auth.login_failed_unknown',
        'auth.lockouts', 'auth.login_throttled', 'auth.two_factor_failed',
        'auth.password_reset_requested', 'auth.password_reset', 'auth.privilege_changes',
        'auth.accounts_created', 'auth.accounts_deleted', 'auth.impersonations', 'auth.gate_denials',
    ];

    /**
     * The minute `histogram` bands, kept in the buckets as `histogram.{band}`.
     */
    public const HISTOGRAM = ['le_50', 'le_100', 'le_250', 'le_500', 'le_1000', 'le_2500', 'le_5000', 'gt_5000'];

    private const TIME = 'Y-m-d\TH:i:s\Z';

    private const FINGERPRINT = '/^[0-9a-f]{64}$/';

    private ?Buckets $buckets = null;

    private ?DistinctSets $sets = null;

    private ?ErrorDetails $details = null;

    public function __construct(private readonly Container $app) {}

    /**
     * The report, capped by Trimmer, and per instance the last minute it
     * covers, to mark sent once the server takes it.
     *
     * @return array{report: array<string, mixed>, sent: array<string, string>}
     */
    public function build(CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc();

        // The first minute still open (or within its grace), and the oldest
        // minute a report may still reach.
        $to = Minute::current($now->subSeconds(self::CLOSE_GRACE_SECONDS));
        $oldest = (Minute::parse($to) ?? $now)->subMinutes(Minute::MAX_RANGE);

        $checks = HealthProbes::checks();
        $stamps = InstanceStamp::all();
        // An instance named only with digits is an int key in PHP.
        $names = self::names(array_map('strval', array_keys($stamps)), Instance::name());

        [$lastSent, $unreadable] = self::progress($names);
        $covered = $lastSent === [] ? null : max($lastSent);

        $instances = [];
        $sent = [];
        $from = $to;
        $dropped = Progress::dropped();

        foreach ($names as $name) {
            $instance = self::describe($name, $stamps[$name] ?? null, $checks);

            if (! isset($unreadable[$name])) {
                $range = Minute::range($lastSent[$name] ?? $oldest->subMinute()->format(Minute::FORMAT), $to);

                try {
                    $instance = [...$instance, ...$this->collect($name, $range)];
                } catch (Throwable) {
                    $range = [];
                }

                if ($range !== []) {
                    $sent[$name] = $range[array_key_last($range)];
                    $from = min($from, $range[0]);
                    $dropped += self::expired($lastSent[$name] ?? null, $covered, $oldest);
                }
            }

            $instances[] = $instance;
        }

        $depths = self::queueDepths();

        $report = [
            'schema' => self::SCHEMA,
            'package' => ['name' => Package::NAME, 'version' => Package::version()],
            'sent_at' => $now->format(self::TIME),
            'reporter' => Instance::name(),
            'window' => ['from' => self::at($from), 'to' => self::at($to)],
            'dropped_minutes' => $dropped,
            'app' => self::app(),
            'checks' => $checks,
            'queue_depths' => $depths['rows'],
            'queue_depths_truncated' => $depths['truncated'],
            'hygiene' => Hygiene::flags(),
            'instances' => $instances,
            'versions' => self::versions(),
        ];

        return ['report' => Trimmer::fit($report), 'sent' => $sent];
    }

    /**
     * The minutes with counts, the error groups (largest first) and the
     * queues (busiest first) of one instance over a range of minutes.
     * Throws when the store cannot be read.
     *
     * @param  list<string>  $range
     * @return array{minutes: list<array<string, mixed>>, errors: list<array<string, mixed>>, queues: list<array<string, mixed>>}
     */
    private function collect(string $instance, array $range): array
    {
        if ($range === []) {
            return ['minutes' => [], 'errors' => [], 'queues' => []];
        }

        $read = $this->buckets()->read($instance, $range);
        $minutes = [];
        $errors = [];
        $queues = [];

        foreach ($range as $minute) {
            $counts = $read[$minute] ?? [];

            if ($counts === []) {
                continue;
            }

            $minutes[] = $this->minute($instance, $minute, $counts);

            foreach ($counts as $key => $n) {
                if (str_starts_with($key, 'error.')) {
                    $fingerprint = substr($key, 6);

                    if (preg_match(self::FINGERPRINT, $fingerprint) === 1) {
                        $errors[$fingerprint] = ($errors[$fingerprint] ?? 0) + $n;
                    }
                } elseif (str_starts_with($key, 'queue.')) {
                    $queues = self::addQueue($queues, substr($key, 6), $n);
                }
            }
        }

        return [
            'minutes' => $minutes,
            'errors' => $this->errorGroups($errors),
            'queues' => self::queueList($queues),
        ];
    }

    /**
     * One minute in the schema shape. The distinct sets are read only when
     * the counter that goes with them moved: a set is only ever added to
     * right after its counter.
     *
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function minute(string $instance, string $minute, array $counts): array
    {
        $counters = [];

        foreach (self::COUNTERS as $key) {
            $counters[$key] = $counts[$key] ?? 0;
        }

        $histogram = [];

        foreach (self::HISTOGRAM as $band) {
            $histogram[$band] = $counts['histogram.'.$band] ?? 0;
        }

        $distinct = [
            'auth.login_failed_accounts' => 0,
            'auth.login_failed_sources' => 0,
            'auth.login_failed_max_per_source' => 0,
            'auth.login_failed_max_per_account' => 0,
            'auth.login_accounts' => 0,
        ];

        if ($counters['auth.login_failed'] > 0) {
            $accounts = $this->sets()->sizes($instance, $minute, 'auth.login_failed_accounts');
            $sources = $this->sets()->sizes($instance, $minute, 'auth.login_failed_sources');

            $distinct['auth.login_failed_accounts'] = $accounts['distinct'];
            $distinct['auth.login_failed_sources'] = $sources['distinct'];
            $distinct['auth.login_failed_max_per_source'] = $sources['max'];
            $distinct['auth.login_failed_max_per_account'] = $accounts['max'];
        }

        if ($counters['auth.login'] > 0) {
            $distinct['auth.login_accounts'] = $this->sets()->sizes($instance, $minute, 'auth.login_accounts')['distinct'];
        }

        return [
            'at' => self::at($minute),
            'counters' => $counters,
            'histogram' => $histogram,
            'distinct' => $distinct,
        ];
    }

    /**
     * Error groups, largest first, named from ErrorDetails; a group whose
     * details have gone is named "unknown".
     *
     * @param  array<string, int>  $counts
     * @return list<array{fingerprint: string, class: string, location: string, count: int}>
     */
    private function errorGroups(array $counts): array
    {
        if ($counts === []) {
            return [];
        }

        $details = $this->details()->of(array_map('strval', array_keys($counts)));
        $groups = [];

        foreach ($counts as $fingerprint => $count) {
            $fingerprint = (string) $fingerprint;

            $groups[] = [
                'fingerprint' => $fingerprint,
                'class' => $details[$fingerprint]['class'] ?? 'unknown',
                'location' => $details[$fingerprint]['location'] ?? 'unknown',
                'count' => $count,
            ];
        }

        usort($groups, static fn (array $a, array $b): int => [$b['count'], $a['fingerprint']] <=> [$a['count'], $b['fingerprint']]);

        return $groups;
    }

    /**
     * Fold one `queue.{name}.{metric}` counter into the per-queue totals. A
     * queue name may hold dots; the metric is after the last one.
     *
     * @param  array<string, array{processed: int, failed: int, slowest_ms: int}>  $queues
     * @return array<string, array{processed: int, failed: int, slowest_ms: int}>
     */
    private static function addQueue(array $queues, string $key, int $n): array
    {
        $dot = strrpos($key, '.');

        if ($dot === false || $dot === 0) {
            return $queues;
        }

        $name = substr($key, 0, $dot);
        $metric = substr($key, $dot + 1);

        if (! in_array($metric, ['processed', 'failed', 'slowest_ms'], true)) {
            return $queues;
        }

        $queue = $queues[$name] ?? ['processed' => 0, 'failed' => 0, 'slowest_ms' => 0];
        $queue[$metric] = $metric === 'slowest_ms' ? max($queue[$metric], $n) : $queue[$metric] + $n;
        $queues[$name] = $queue;

        return $queues;
    }

    /**
     * Busiest first, so the cap of 20 keeps the queues that matter.
     *
     * @param  array<string, array{processed: int, failed: int, slowest_ms: int}>  $queues
     * @return list<array{name: string, processed: int, failed: int, slowest_ms: int}>
     */
    private static function queueList(array $queues): array
    {
        $list = [];

        foreach ($queues as $name => $queue) {
            $list[] = ['name' => (string) $name, ...$queue];
        }

        usort($list, static fn (array $a, array $b): int => [$b['processed'] + $b['failed'], $a['name']] <=> [$a['processed'] + $a['failed'], $b['name']]);

        return $list;
    }

    /**
     * The instances to report, in the order they first stamped, with this
     * machine always among them (it may not have stamped yet, or the store
     * may be down). At most 50: past that, the latest to join wait.
     *
     * @param  list<string>  $stamped
     * @return list<string>
     */
    private static function names(array $stamped, string $reporter): array
    {
        $others = array_slice(array_values(array_filter($stamped, static fn (string $name): bool => $name !== $reporter)), 0, self::MAX_INSTANCES - 1);

        if (! in_array($reporter, $stamped, true)) {
            return [...$others, $reporter];
        }

        return array_values(array_filter($stamped, static fn (string $name): bool => $name === $reporter || in_array($name, $others, true)));
    }

    /**
     * The last minute sent per instance (instances never sent left out),
     * and the instances whose progress could not be read.
     *
     * @param  list<string>  $names
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private static function progress(array $names): array
    {
        $lastSent = [];
        $unreadable = [];

        foreach ($names as $name) {
            try {
                $minute = Progress::lastSent($name);
            } catch (Throwable) {
                $unreadable[$name] = true;

                continue;
            }

            if ($minute !== null) {
                $lastSent[$name] = $minute;
            }
        }

        return [$lastSent, $unreadable];
    }

    /**
     * Minutes of one instance that passed out of reach unsent: those after
     * its last sent minute and before the oldest a report can still read.
     *
     * Only minutes after the newest progress of any instance count. Up to
     * that minute reports were accepted, and each covered every instance
     * that had stamped; an instance missing from them had not stamped and so
     * counted nothing (a machine switched off overnight). A new instance has
     * lost nothing.
     */
    private static function expired(?string $lastSent, ?string $covered, CarbonImmutable $oldest): int
    {
        $after = Minute::parse(max((string) $lastSent, (string) $covered));

        if ($lastSent === null || $after === null || $after >= $oldest) {
            return 0;
        }

        return max(0, (int) $after->diffInMinutes($oldest) - 1);
    }

    /**
     * An instance's fields besides its minutes, errors and queues. This
     * machine without a stamp is described from the probes it just ran: it
     * is plainly not quiet.
     *
     * @param  array{hostname: string, stamped_at: string, quiet: bool, local: ?array<string, mixed>}|null  $stamp
     * @param  array<string, array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private static function describe(string $name, ?array $stamp, array $checks): array
    {
        return [
            'name' => $name,
            'hostname' => $stamp['hostname'] ?? Instance::hostname(),
            'stamped_at' => $stamp['stamped_at'] ?? null,
            'quiet' => $stamp['quiet'] ?? false,
            'local' => $stamp === null ? ['storage' => $checks['storage'], 'php' => PHP_VERSION] : $stamp['local'],
            'minutes' => [],
            'minutes_truncated' => 0,
            'errors' => [],
            'errors_truncated' => 0,
            'queues' => [],
        ];
    }

    /**
     * @return array{environment: string, debug: bool, php: string, laravel: string, timezone: string}
     */
    private static function app(): array
    {
        $environment = config('app.env');
        $timezone = config('app.timezone');

        return [
            'environment' => is_string($environment) && $environment !== '' ? $environment : 'production',
            'debug' => filter_var(config('app.debug'), FILTER_VALIDATE_BOOLEAN),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'timezone' => is_string($timezone) && $timezone !== '' ? $timezone : 'UTC',
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: int}
     */
    private static function queueDepths(): array
    {
        try {
            return QueueDepths::rows();
        } catch (Throwable) {
            return ['rows' => [], 'truncated' => 0];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function versions(): ?array
    {
        if (! Versions::due()) {
            return null;
        }

        try {
            return Versions::section();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A minute as ISO 8601 UTC.
     */
    private static function at(string $minute): string
    {
        return Minute::parse($minute)?->format(self::TIME) ?? $minute;
    }

    private function buckets(): Buckets
    {
        return $this->buckets ??= $this->app->make(Buckets::class);
    }

    private function sets(): DistinctSets
    {
        return $this->sets ??= $this->app->make(DistinctSets::class);
    }

    private function details(): ErrorDetails
    {
        return $this->details ??= $this->app->make(ErrorDetails::class);
    }
}
