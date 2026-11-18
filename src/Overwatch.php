<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent;

use Arisultima\OverwatchAgent\Recording\Recorder;
use Throwable;

/**
 * Hooks for apps whose sign-in flow raises none of the events the package
 * listens for. Call one where the thing happens; it counts once in the
 * current minute and does nothing while reporting is off. Where the event
 * exists and is raised (Fortify's two-factor failure, Laravel's reset link
 * sent, spatie's role events), the event is already counted: do not call the
 * hook as well. None of them ever throws.
 */
final class Overwatch
{
    /**
     * A wrong two-factor code.
     */
    public static function twoFactorFailed(): void
    {
        self::count('auth.two_factor_failed');
    }

    /**
     * A password reset link sent.
     */
    public static function passwordResetRequested(): void
    {
        self::count('auth.password_reset_requested');
    }

    /**
     * A role or permission given to or taken from an account.
     */
    public static function privilegeChanged(): void
    {
        self::count('auth.privilege_changes');
    }

    /**
     * A person started acting as another account.
     */
    public static function impersonated(): void
    {
        self::count('auth.impersonations');
    }

    private static function count(string $key): void
    {
        try {
            if (! Config::enabled()) {
                return;
            }

            app(Recorder::class)->count($key);
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }
}
