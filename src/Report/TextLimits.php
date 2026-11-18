<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Report;

use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Text;

/**
 * The schema's text limits, applied to a built report: every text field the
 * server checks is cut to its limit by characters and filled with "unknown"
 * when it would be empty, so no single value from the app (a long queue
 * name, an empty APP_ENV) gets the whole report refused. Nothing else in
 * the report is touched. The `versions` rows are left to Versions, which
 * skips a package it cannot send whole rather than cut its name.
 */
final class TextLimits
{
    public const PACKAGE = 255;

    public const REPORTER = 100;

    public const APP = 64;

    public const CHECK_ERROR = 255;

    public const CHECK_FIELD = 64;

    public const QUEUE_CONNECTION = 64;

    public const QUEUE_NAME = 255;

    public const INSTANCE_NAME = Instance::MAX_LENGTH;

    public const HOSTNAME = 255;

    public const LOCAL_PHP = 64;

    public const ERROR_FIELD = 512;

    public const VERSIONS_FIELD = 255;

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function apply(array $report): array
    {
        $report = self::fit($report, ['package', 'name'], self::PACKAGE);
        $report = self::fit($report, ['package', 'version'], self::PACKAGE);
        $report = self::fit($report, ['reporter'], self::REPORTER);

        foreach (['environment', 'php', 'laravel', 'timezone'] as $field) {
            $report = self::fit($report, ['app', $field], self::APP);
        }

        if (is_array($report['checks'] ?? null)) {
            $report['checks'] = self::checks($report['checks']);
        }

        foreach (self::rows($report, 'queue_depths') as $i) {
            $report = self::fit($report, ['queue_depths', $i, 'connection'], self::QUEUE_CONNECTION);
            $report = self::fit($report, ['queue_depths', $i, 'name'], self::QUEUE_NAME);
        }

        foreach (self::rows($report, 'instances') as $i) {
            if (is_array($report['instances'][$i])) {
                $report['instances'][$i] = self::instance($report['instances'][$i]);
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>
     */
    private static function checks(array $checks): array
    {
        foreach ($checks as $key => $check) {
            if (is_array($check)) {
                $checks[$key] = self::check($check);
            }
        }

        $checks = self::fit($checks, ['queue', 'driver'], self::CHECK_FIELD);

        return self::fit($checks, ['horizon', 'status'], self::CHECK_FIELD);
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private static function check(array $check): array
    {
        return self::fit($check, ['error'], self::CHECK_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $instance
     * @return array<array-key, mixed>
     */
    private static function instance(array $instance): array
    {
        $instance = self::fit($instance, ['name'], self::INSTANCE_NAME);
        $instance = self::fit($instance, ['hostname'], self::HOSTNAME);
        $instance = self::fit($instance, ['local', 'php'], self::LOCAL_PHP);

        if (is_array($instance['local']['storage'] ?? null)) {
            $instance['local']['storage'] = self::check($instance['local']['storage']);
        }

        foreach (self::rows($instance, 'errors') as $i) {
            $instance = self::fit($instance, ['errors', $i, 'class'], self::ERROR_FIELD);
            $instance = self::fit($instance, ['errors', $i, 'location'], self::ERROR_FIELD);
        }

        foreach (self::rows($instance, 'queues') as $i) {
            $instance = self::fit($instance, ['queues', $i, 'name'], self::QUEUE_NAME);
        }

        return $instance;
    }

    /**
     * Fit the field at a path when its parent is there; a field that is
     * missing is left missing (a check that passed has no `error`).
     *
     * @param  array<array-key, mixed>  $data
     * @param  non-empty-list<array-key>  $path
     * @return array<array-key, mixed>
     */
    private static function fit(array $data, array $path, int $max): array
    {
        $key = array_shift($path);

        if (! array_key_exists($key, $data)) {
            return $data;
        }

        if ($path === []) {
            $data[$key] = Text::fit($data[$key], $max);
        } elseif (is_array($data[$key])) {
            $data[$key] = self::fit($data[$key], $path, $max);
        }

        return $data;
    }

    /**
     * The keys of a list in the data; none when it is not a list.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array-key>
     */
    private static function rows(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? array_keys($data[$key]) : [];
    }
}
