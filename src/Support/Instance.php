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
     * OVERWATCH_INSTANCE when set, else the hostname, lowercased and cut to
     * 100 characters, so machines named "Web-1" and "web-1" report as one
     * instance under one name.
     */
    public static function name(): string
    {
        return Text::fit(mb_strtolower(Config::instanceName() ?? self::hostname(), 'UTF-8'), self::MAX_LENGTH);
    }

    public static function hostname(): string
    {
        $hostname = gethostname();

        return $hostname === false || $hostname === '' ? 'unknown' : $hostname;
    }
}
