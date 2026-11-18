<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes;

use Arisultima\OverwatchAgent\Recording\Guard;
use Arisultima\OverwatchAgent\Recording\Keys;
use Arisultima\OverwatchAgent\Recording\Locks;
use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Store;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Each machine notes, about once a minute, that it is alive and what its own
 * disk and PHP look like, so the reporting node can describe machines it
 * cannot probe. The stamps live in the shared overwatch store, with an index
 * of instance names because a cache cannot list its keys.
 *
 * maybe() runs when a request or command ends and on every queue worker
 * loop, so it is cheap: no cache call at all while this process stamped
 * within the minute, and one read when another process (or the last
 * request) did.
 */
final class InstanceStamp
{
    public const EVERY_SECONDS = 60;

    /**
     * A stamp older than this marks its instance quiet.
     */
    public const QUIET_SECONDS = 600;

    /**
     * A stamp older than this is gone: its key expires and the instance
     * leaves the index.
     */
    public const DROP_SECONDS = 3700;

    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Unix time of the stamp this process last wrote or found fresh.
     */
    private static ?int $stampedAt = null;

    /**
     * Stamp this instance unless it was stamped in the last 60 seconds.
     * Never throws.
     */
    public static function maybe(): void
    {
        $now = Carbon::now()->getTimestamp();

        if (self::fresh(self::$stampedAt, $now)) {
            return;
        }

        Guard::quietly(static function () use ($now): void {
            self::stamp(Store::cache(), Instance::name(), $now);
        });
    }

    /**
     * Every instance stamped within the hour, by name, in the order they
     * first stamped. Instances whose stamp has gone are dropped from the
     * index. Empty when the store cannot be read.
     *
     * @return array<string, array{hostname: string, stamped_at: string, quiet: bool, local: ?array<string, mixed>}>
     */
    public static function all(): array
    {
        try {
            return self::read(Store::cache(), Carbon::now()->getTimestamp());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Forget this process's last stamp. For tests, which share one process.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$stampedAt = null;
    }

    private static function stamp(Repository $cache, string $instance, int $now): void
    {
        $found = self::time($cache->get(Keys::stamp($instance)));

        if (self::fresh($found, $now)) {
            self::$stampedAt = $found;

            return;
        }

        $cache->put(Keys::stamp($instance), [
            'hostname' => Instance::hostname(),
            'stamped_at' => gmdate(self::FORMAT, $now),
            'local' => ['storage' => HealthProbes::storage(), 'php' => PHP_VERSION],
        ], self::DROP_SECONDS);

        self::$stampedAt = $now;

        $index = $cache->get(Keys::instances());

        if (is_array($index) && in_array($instance, $index, true)) {
            return;
        }

        Locks::run($cache, Keys::instances().':lock', static function () use ($cache, $instance): void {
            $index = self::names($cache->get(Keys::instances()));

            if (! in_array($instance, $index, true)) {
                $index[] = $instance;
                $cache->forever(Keys::instances(), $index);
            }
        });
    }

    /**
     * @return array<string, array{hostname: string, stamped_at: string, quiet: bool, local: ?array<string, mixed>}>
     */
    private static function read(Repository $cache, int $now): array
    {
        $names = self::names($cache->get(Keys::instances()));

        if ($names === []) {
            return [];
        }

        $keys = array_map(Keys::stamp(...), $names);
        $stamps = $cache->many($keys);
        $all = [];
        $gone = [];

        foreach ($names as $i => $instance) {
            $stamp = $stamps[$keys[$i]] ?? null;
            $at = self::time($stamp);

            if ($at === null || $now - $at > self::DROP_SECONDS) {
                $gone[] = $instance;

                continue;
            }

            $all[$instance] = [
                'hostname' => is_string($stamp['hostname'] ?? null) ? $stamp['hostname'] : 'unknown',
                'stamped_at' => gmdate(self::FORMAT, $at),
                'quiet' => $now - $at > self::QUIET_SECONDS,
                'local' => is_array($stamp['local'] ?? null) ? $stamp['local'] : null,
            ];
        }

        if ($gone !== []) {
            Locks::run($cache, Keys::instances().':lock', static function () use ($cache, $gone): void {
                $index = array_values(array_diff(self::names($cache->get(Keys::instances())), $gone));
                $cache->forever(Keys::instances(), $index);
            });
        }

        return $all;
    }

    /**
     * Whether a stamp at this time is less than a minute old. A stamp from
     * the future (a clock stepped back) is not fresh.
     */
    private static function fresh(?int $at, int $now): bool
    {
        return $at !== null && $at <= $now && $now - $at < self::EVERY_SECONDS;
    }

    /**
     * The Unix time of a stored stamp, or null when it is missing or bad.
     */
    private static function time(mixed $stamp): ?int
    {
        if (! is_array($stamp) || ! is_string($stamp['stamped_at'] ?? null)) {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $stamp['stamped_at'], new DateTimeZone('UTC'));

        return $at === false ? null : $at->getTimestamp();
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $index): array
    {
        return is_array($index) ? array_values(array_filter($index, is_string(...))) : [];
    }
}
