<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Report;

/**
 * Fits a report into the schema's size caps, and is the one JSON encoder for
 * reports, so what is measured here is what is sent.
 *
 * The lists are cut in the schema's order: error groups beyond 20 per
 * instance, then queue depths beyond 20, then the oldest minutes across all
 * instances until the report without `versions` fits in 64 KB. Each cut
 * list sets its `*_truncated` count, and each minute cut is also counted
 * into `dropped_minutes`. Should the report still not fit with every minute
 * gone (50 instances with 20 long error groups each can be larger than 64
 * KB), the smallest error groups go next, so the checks still arrive rather
 * than the whole report being refused; after them, each instance's queues
 * down to 5. The `versions` section has its own cap and loses packages from
 * the end of its sorted list.
 *
 * Lists are expected in the order the builder makes them: minutes oldest
 * first, error groups largest first.
 */
final class Trimmer
{
    /**
     * Uncompressed JSON bytes of the report without `versions`.
     */
    public const REPORT_BYTES = 65536;

    /**
     * Uncompressed JSON bytes of the `versions` section alone.
     */
    public const VERSIONS_BYTES = 196608;

    /**
     * Uncompressed JSON bytes of the whole body. The two caps above keep it.
     */
    public const BODY_BYTES = 262144;

    public const MAX_ERRORS = 20;

    public const MAX_QUEUE_DEPTHS = 20;

    public const MAX_QUEUES = 20;

    public const MAX_MINUTES = 60;

    /**
     * Queues each instance keeps when the report fits no other way.
     */
    public const LAST_RESORT_QUEUES = 5;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * A report, or any part of one, as the JSON that is sent.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function encode(array $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function fit(array $report): array
    {
        $report = self::capErrors($report);
        $report = self::capQueueDepths($report);
        $report = self::capInstanceLists($report);
        $report = self::fitMinutes($report);
        $report = self::fitErrors($report);
        $report = self::fitQueues($report);

        return self::fitVersions($report);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function capErrors(array $report): array
    {
        foreach (self::instanceKeys($report) as $i) {
            $errors = $report['instances'][$i]['errors'] ?? [];

            if (is_array($errors) && count($errors) > self::MAX_ERRORS) {
                $report['instances'][$i]['errors'] = array_slice($errors, 0, self::MAX_ERRORS);
                $report['instances'][$i]['errors_truncated'] = self::int($report['instances'][$i]['errors_truncated'] ?? 0)
                    + count($errors) - self::MAX_ERRORS;
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function capQueueDepths(array $report): array
    {
        $rows = $report['queue_depths'] ?? [];

        if (is_array($rows) && count($rows) > self::MAX_QUEUE_DEPTHS) {
            $report['queue_depths'] = array_slice($rows, 0, self::MAX_QUEUE_DEPTHS);
            $report['queue_depths_truncated'] = self::int($report['queue_depths_truncated'] ?? 0)
                + count($rows) - self::MAX_QUEUE_DEPTHS;
        }

        return $report;
    }

    /**
     * At most 60 minutes per instance (the oldest go, counted as truncated
     * and dropped) and at most 20 queues (the schema keeps no count for
     * those; the builder lists the busiest first).
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function capInstanceLists(array $report): array
    {
        foreach (self::instanceKeys($report) as $i) {
            $minutes = $report['instances'][$i]['minutes'] ?? [];

            if (is_array($minutes) && count($minutes) > self::MAX_MINUTES) {
                $cut = count($minutes) - self::MAX_MINUTES;
                $report['instances'][$i]['minutes'] = array_slice($minutes, $cut);
                $report = self::countCutMinutes($report, $i, $cut);
            }

            $queues = $report['instances'][$i]['queues'] ?? [];

            if (is_array($queues) && count($queues) > self::MAX_QUEUES) {
                $report['instances'][$i]['queues'] = array_slice($queues, 0, self::MAX_QUEUES);
            }
        }

        return $report;
    }

    /**
     * Cut the oldest minutes across all instances until the report fits.
     * Each round cuts as many as the excess needs by their own size, then
     * measures again, so a report of 3000 minutes is encoded a few times,
     * not once per minute.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function fitMinutes(array $report): array
    {
        $size = self::reportBytes($report);

        if ($size <= self::REPORT_BYTES) {
            return $report;
        }

        $original = [];
        $queue = [];

        foreach (self::instanceKeys($report) as $i) {
            $original[$i] = array_values(is_array($report['instances'][$i]['minutes'] ?? null) ? $report['instances'][$i]['minutes'] : []);

            foreach ($original[$i] as $j => $minute) {
                $queue[] = [is_array($minute) ? (string) ($minute['at'] ?? '') : '', $i, $j];
            }
        }

        // Oldest first; equal minutes in instance order.
        usort($queue, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $cut = array_fill_keys(array_keys($original), 0);
        $next = 0;

        while ($size > self::REPORT_BYTES && $next < count($queue)) {
            $excess = $size - self::REPORT_BYTES;
            $saved = 0;
            $round = array_fill_keys(array_keys($original), 0);

            while ($saved < $excess && $next < count($queue)) {
                [, $i, $j] = $queue[$next++];
                $saved += strlen(self::encode((array) $original[$i][$j])) + 1;
                $cut[$i]++;
                $round[$i]++;
            }

            foreach ($round as $i => $n) {
                if ($n > 0) {
                    $report['instances'][$i]['minutes'] = array_slice($original[$i], $cut[$i]);
                    $report = self::countCutMinutes($report, $i, $n);
                }
            }

            $size = self::reportBytes($report);
        }

        return $report;
    }

    /**
     * The last resort, past the schema's order: with every minute cut and
     * the report still too large, cut the smallest error groups across
     * instances.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function fitErrors(array $report): array
    {
        $size = self::reportBytes($report);

        if ($size <= self::REPORT_BYTES) {
            return $report;
        }

        $original = [];
        $queue = [];

        foreach (self::instanceKeys($report) as $i) {
            $original[$i] = array_values(is_array($report['instances'][$i]['errors'] ?? null) ? $report['instances'][$i]['errors'] : []);

            foreach ($original[$i] as $j => $group) {
                $queue[] = [is_array($group) ? self::int($group['count'] ?? 0) : 0, $i, $j];
            }
        }

        // Smallest first; each instance loses its list from the tail.
        usort($queue, static fn (array $a, array $b): int => [$a[0], $a[1], $b[2]] <=> [$b[0], $b[1], $a[2]]);

        $kept = array_map('count', $original);
        $next = 0;

        while ($size > self::REPORT_BYTES && $next < count($queue)) {
            $excess = $size - self::REPORT_BYTES;
            $saved = 0;
            $round = array_fill_keys(array_keys($original), 0);

            while ($saved < $excess && $next < count($queue)) {
                [, $i, $j] = $queue[$next++];
                $saved += strlen(self::encode((array) $original[$i][$j])) + 1;
                $kept[$i]--;
                $round[$i]++;
            }

            foreach ($round as $i => $n) {
                if ($n > 0) {
                    $report['instances'][$i]['errors'] = array_slice($original[$i], 0, $kept[$i]);
                    $report['instances'][$i]['errors_truncated'] = self::int($report['instances'][$i]['errors_truncated'] ?? 0) + $n;
                }
            }

            $size = self::reportBytes($report);
        }

        return $report;
    }

    /**
     * The very last step: when even without minutes and error groups the
     * report is too large (50 instances with 20 queues each), every instance
     * keeps its 5 queues with the most jobs waiting, by the queue depths
     * measured on the reporting node, then the most jobs run. The schema has
     * no count for cut queues, as for instances past 50.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function fitQueues(array $report): array
    {
        if (self::reportBytes($report) <= self::REPORT_BYTES) {
            return $report;
        }

        $pending = [];

        foreach (is_array($report['queue_depths'] ?? null) ? $report['queue_depths'] : [] as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $pending[$row['name']] = max($pending[$row['name']] ?? 0, self::int($row['pending'] ?? 0));
            }
        }

        $rank = static fn (array $queue): array => [
            $pending[(string) ($queue['name'] ?? '')] ?? -1,
            self::int($queue['processed'] ?? 0) + self::int($queue['failed'] ?? 0),
        ];

        foreach (self::instanceKeys($report) as $i) {
            $queues = $report['instances'][$i]['queues'] ?? [];

            if (! is_array($queues) || count($queues) <= self::LAST_RESORT_QUEUES) {
                continue;
            }

            $queues = array_values(array_filter($queues, 'is_array'));

            usort($queues, static fn (array $a, array $b): int => [...$rank($b), (string) ($a['name'] ?? '')] <=> [...$rank($a), (string) ($b['name'] ?? '')]);

            $report['instances'][$i]['queues'] = array_slice($queues, 0, self::LAST_RESORT_QUEUES);
        }

        return $report;
    }

    /**
     * Cut packages from the end of the sorted list until the section fits.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function fitVersions(array $report): array
    {
        $versions = $report['versions'] ?? null;

        if (! is_array($versions) || ! is_array($versions['packages'] ?? null)) {
            return $report;
        }

        $size = strlen(self::encode($versions));
        $packages = array_values($versions['packages']);
        $kept = count($packages);

        while ($size > self::VERSIONS_BYTES && $kept > 0) {
            $excess = $size - self::VERSIONS_BYTES;
            $saved = 0;
            $cut = 0;

            while ($saved < $excess && $kept > 0) {
                $saved += strlen(self::encode((array) $packages[--$kept])) + 1;
                $cut++;
            }

            $versions['packages'] = array_slice($packages, 0, $kept);
            $versions['packages_truncated'] = self::int($versions['packages_truncated'] ?? 0) + $cut;
            $size = strlen(self::encode($versions));
        }

        $report['versions'] = $versions;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function countCutMinutes(array $report, int|string $i, int $n): array
    {
        $report['instances'][$i]['minutes_truncated'] = self::int($report['instances'][$i]['minutes_truncated'] ?? 0) + $n;
        $report['dropped_minutes'] = self::int($report['dropped_minutes'] ?? 0) + $n;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function reportBytes(array $report): int
    {
        return strlen(self::encode([...$report, 'versions' => null]));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<int|string>
     */
    private static function instanceKeys(array $report): array
    {
        $instances = $report['instances'] ?? [];

        if (! is_array($instances)) {
            return [];
        }

        return array_keys(array_filter($instances, 'is_array'));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
