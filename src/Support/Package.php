<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * This package's own name and installed version, for the report's `package`
 * object and the User-Agent.
 */
final class Package
{
    public const NAME = 'arisultima/overwatch-laravel-agent';

    /**
     * The installed version as Composer reports it; "unknown" when Composer
     * cannot tell (a copy loaded without Composer's runtime).
     */
    public static function version(): string
    {
        try {
            $version = class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion(self::NAME) : null;
        } catch (Throwable) {
            $version = null;
        }

        return is_string($version) && $version !== '' ? $version : 'unknown';
    }
}
