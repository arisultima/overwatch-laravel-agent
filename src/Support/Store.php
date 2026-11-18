<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Support;

use Arisultima\OverwatchAgent\Config;
use Illuminate\Contracts\Cache\Repository;

/**
 * The overwatch cache store: the configured one, else the default store.
 *
 * @internal
 */
final class Store
{
    public static function cache(): Repository
    {
        return app('cache')->store(Config::store());
    }

    /**
     * What is wrong with the store for counting, one plain sentence naming
     * the setting to fix; null when nothing is. The array driver keeps
     * counts inside one PHP process, so the scheduled report (its own
     * process) never sees what requests and workers counted; the null
     * driver keeps nothing at all. Read from config only: nothing connects.
     */
    public static function warning(): ?string
    {
        $name = Config::store() ?? config('cache.default');
        $driver = is_string($name) ? config('cache.stores.'.$name.'.driver') : null;

        $meaning = match ($driver) {
            'array' => 'keeps counts inside one PHP process, so they are never shared with the scheduled report or kept between requests',
            'null' => 'keeps nothing, so every count is lost',
            default => null,
        };

        if ($meaning === null) {
            return null;
        }

        return sprintf(
            'The overwatch cache store "%s" uses the %s driver, which %s. Set OVERWATCH_CACHE_STORE to a store every machine shares, such as redis or database.',
            $name,
            $driver,
            $meaning,
        );
    }
}
