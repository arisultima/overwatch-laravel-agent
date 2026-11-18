<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent;

/**
 * Typed reads of the overwatch config, so no other class touches the raw keys.
 */
final class Config
{
    /**
     * Reporting is on only with a token set and `enabled` left true.
     */
    public static function enabled(): bool
    {
        return (bool) config('overwatch.enabled', true) && self::token() !== '';
    }

    public static function url(): string
    {
        return rtrim(self::string('overwatch.url') ?? '', '/');
    }

    public static function token(): string
    {
        return self::string('overwatch.token') ?? '';
    }

    /**
     * The cache store for the counters; null means the default store.
     */
    public static function store(): ?string
    {
        return self::string('overwatch.cache_store');
    }

    public static function slowQueryMs(): int
    {
        return (int) config('overwatch.slow_query_ms', 500);
    }

    public static function slowRequestMs(): int
    {
        return (int) config('overwatch.slow_request_ms', 1000);
    }

    /**
     * Route names of the sign-in endpoints, for counting throttled sign-ins.
     *
     * @return list<string>
     */
    public static function signinRoutes(): array
    {
        $routes = config('overwatch.signin_routes', []);

        return is_array($routes) ? array_values(array_map('strval', $routes)) : [];
    }

    /**
     * @return list<string>
     */
    public static function privilegedRoles(): array
    {
        $roles = config('overwatch.privileged_roles', []);

        return is_array($roles) ? array_values(array_map('strval', $roles)) : [];
    }

    /**
     * The user model whose created and deleted events are counted; falls back
     * to the default auth provider's model.
     */
    public static function userModel(): ?string
    {
        return self::string('overwatch.user_model') ?? self::string('auth.providers.users.model');
    }

    /**
     * The configured instance name; null means the hostname is used.
     */
    public static function instanceName(): ?string
    {
        return self::string('overwatch.instance');
    }

    /**
     * A config value as a trimmed string, with null and empty read as null.
     */
    private static function string(string $key): ?string
    {
        $value = config($key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
