<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent;

/**
 * Typed reads of the overwatch config, so no other class touches the raw keys.
 */
final class Config
{
    /**
     * Reporting is on only with `enabled` left true and both the URL and the
     * token set: exactly when missing() is empty.
     */
    public static function enabled(): bool
    {
        return (bool) config('overwatch.enabled', true) && self::token() !== '' && self::url() !== '';
    }

    /**
     * What stops reports going out, one plain sentence each, naming the
     * setting to fix; empty when nothing does. Never quotes a value.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $missing = [];

        if (! (bool) config('overwatch.enabled', true)) {
            $missing[] = 'OVERWATCH_ENABLED is false, so nothing is recorded or sent. Remove it from .env, or set it to true.';
        }

        if (self::token() === '') {
            $missing[] = 'OVERWATCH_TOKEN is not set. Add the app token your Overwatch server issued to .env.';
        }

        if (self::url() === '') {
            $missing[] = 'OVERWATCH_URL is not set. Add the address of your Overwatch server to .env, for example https://overwatch.example.com.';
        }

        return $missing;
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
     * Queue names on the default connection to report the depth of; the
     * connection's default queue when none are set.
     *
     * @return list<string>
     */
    public static function queues(): array
    {
        $queues = config('overwatch.queues', []);
        $names = [];

        foreach (is_array($queues) ? $queues : [$queues] as $name) {
            if (is_scalar($name) && trim((string) $name) !== '') {
                $names[] = trim((string) $name);
            }
        }

        return $names !== [] ? array_values(array_unique($names)) : [self::defaultQueue()];
    }

    /**
     * The app's default queue connection.
     */
    public static function queueConnection(): string
    {
        return self::string('queue.default') ?? 'sync';
    }

    /**
     * The default queue name on the default connection.
     */
    public static function defaultQueue(): string
    {
        return self::string('queue.connections.'.self::queueConnection().'.queue') ?? 'default';
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
