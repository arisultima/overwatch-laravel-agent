<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Report;

use Arisultima\OverwatchAgent\Recording\Keys;
use Arisultima\OverwatchAgent\Support\Minute;
use Arisultima\OverwatchAgent\Support\Store;
use Throwable;

/**
 * What the reporter has handed over: per instance, the last minute a report
 * covered and the server took; and a count of minutes lost that the next
 * report cannot work out itself. Both live in the overwatch store without
 * expiry, so every node that may report sees the same progress.
 *
 * Only the reporting node writes them, one run at a time (the schedule runs
 * the report withoutOverlapping and on one server), so a plain read and
 * write is enough.
 */
final class Progress
{
    /**
     * The last minute (YmdHi) sent for this instance, or null when none was.
     * Throws when the store cannot be read: guessing "never sent" would send
     * up to 60 minutes a second time.
     *
     * The instance may be an int: a name made only of digits comes back as
     * an int array key, as in the builder's `sent`.
     */
    public static function lastSent(int|string $instance): ?string
    {
        $minute = Store::cache()->get(Keys::progress((string) $instance));

        // A Redis store keeps a numeric string raw and may hand back an int.
        if (is_int($minute)) {
            $minute = (string) $minute;
        }

        return is_string($minute) && Minute::parse($minute) !== null ? $minute : null;
    }

    /**
     * Never throws; a minute that could not be marked is sent again. The
     * instance may be an int key from `sent`, as in lastSent().
     */
    public static function markSent(int|string $instance, string $minute): void
    {
        if (Minute::parse($minute) === null) {
            return;
        }

        try {
            Store::cache()->forever(Keys::progress((string) $instance), $minute);
        } catch (Throwable) {
            // The next report covers the minute again.
        }
    }

    /**
     * Minutes recorded as lost since the last accepted report; 0 when the
     * store cannot be read.
     */
    public static function dropped(): int
    {
        try {
            $dropped = Store::cache()->get(Keys::dropped());
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($dropped) ? max(0, (int) $dropped) : 0;
    }

    /**
     * Never throws.
     */
    public static function addDropped(int $n): void
    {
        if ($n <= 0) {
            return;
        }

        try {
            Store::cache()->forever(Keys::dropped(), self::dropped() + $n);
        } catch (Throwable) {
            // Lost with the store.
        }
    }

    /**
     * Never throws.
     */
    public static function clearDropped(): void
    {
        try {
            Store::cache()->forget(Keys::dropped());
        } catch (Throwable) {
            // Read as 0 while the store is down anyway.
        }
    }
}
