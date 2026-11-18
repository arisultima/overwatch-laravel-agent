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
}
