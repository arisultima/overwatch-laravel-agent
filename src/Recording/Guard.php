<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Closure;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Recording must never throw into the host app or slow it down. Every write
 * runs through quietly(): whatever it throws is swallowed, and after one
 * failure this process stops recording for 60 seconds, so a store that is
 * down costs one failed call a minute instead of one per event.
 */
final class Guard
{
    public const PAUSE_SECONDS = 60;

    /**
     * Unix time until which recording is paused in this process.
     */
    private static ?int $pausedUntil = null;

    public static function quietly(Closure $fn): void
    {
        if (self::paused()) {
            return;
        }

        try {
            $fn();
        } catch (Throwable) {
            self::$pausedUntil = Carbon::now()->getTimestamp() + self::PAUSE_SECONDS;
        }
    }

    public static function paused(): bool
    {
        if (self::$pausedUntil === null) {
            return false;
        }

        if (Carbon::now()->getTimestamp() < self::$pausedUntil) {
            return true;
        }

        self::$pausedUntil = null;

        return false;
    }

    /**
     * Lift the pause. For tests, which share one process.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$pausedUntil = null;
    }
}
