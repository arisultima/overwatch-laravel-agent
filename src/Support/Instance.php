<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Support;

use Arisultima\OverwatchAgent\Config;

/**
 * The machine this process runs on, as the report names it.
 */
final class Instance
{
    public const MAX_LENGTH = 100;

    /**
     * OVERWATCH_INSTANCE when set, else the hostname, cut to 100 characters.
     */
    public static function name(): string
    {
        return mb_substr(Config::instanceName() ?? self::hostname(), 0, self::MAX_LENGTH);
    }

    public static function hostname(): string
    {
        $hostname = gethostname();

        return $hostname === false || $hostname === '' ? 'unknown' : $hostname;
    }
}
