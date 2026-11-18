<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes;

use Closure;
use Throwable;

/**
 * Settings worth a warning, as booleans only: never the values behind them.
 * Read on the reporting node at report time; each is a config read or a
 * file check the framework already makes.
 */
final class Hygiene
{
    /**
     * @return array{debug_in_production: bool, https: bool, secure_session_cookie: bool, maintenance_mode: bool, config_cached: bool, routes_cached: bool, app_key_set: bool}
     */
    public static function flags(): array
    {
        return [
            'debug_in_production' => self::flag(static fn (): bool => config('app.env') === 'production' && self::truthy(config('app.debug'))),
            'https' => self::flag(static fn (): bool => str_starts_with(strtolower(trim((string) config('app.url'))), 'https://')),
            'secure_session_cookie' => self::flag(static fn (): bool => self::truthy(config('session.secure'))),
            'maintenance_mode' => self::flag(static fn (): bool => app()->isDownForMaintenance()),
            'config_cached' => self::flag(static fn (): bool => app()->configurationIsCached()),
            'routes_cached' => self::flag(static fn (): bool => app()->routesAreCached()),
            'app_key_set' => self::flag(static fn (): bool => is_string(config('app.key')) && trim(config('app.key')) !== ''),
        ];
    }

    /**
     * A flag that cannot be read is false: it is not known to be set.
     *
     * @param  Closure(): bool  $read
     */
    private static function flag(Closure $read): bool
    {
        try {
            return $read();
        } catch (Throwable) {
            return false;
        }
    }

    private static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
