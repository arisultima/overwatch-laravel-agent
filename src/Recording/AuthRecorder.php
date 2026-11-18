<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Arisultima\OverwatchAgent\Config;
use BackedEnum;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Throwable;
use UnitEnum;

/**
 * Counts sign-ins, failed sign-ins, lockouts, password resets and failed
 * two-factor challenges. The account named in a failed sign-in, the client
 * address and the signed-in account's identifier go only to distinct sets,
 * which keep a keyed hash of each; nothing else of the user, the credentials
 * or the request is read or kept.
 */
final class AuthRecorder
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Container $app,
    ) {}

    public function failed(Failed $event): void
    {
        try {
            $this->recorder->count('auth.login_failed');

            if ($event->user === null) {
                $this->recorder->count('auth.login_failed_unknown');
            }

            $account = self::accountIn($event->credentials);

            if ($account !== null) {
                $this->recorder->distinct('auth.login_failed_accounts', $account);
            }

            $address = $this->address();

            if ($address !== null) {
                $this->recorder->distinct('auth.login_failed_sources', $address);
            }
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }

    public function login(Login $event): void
    {
        try {
            $this->recorder->count('auth.login');

            $user = $event->user;
            $id = $user->getAuthIdentifier();

            // Qualified by the user class, so id 1 on two user tables (two
            // guards) stays two accounts.
            if (is_scalar($id) && (string) $id !== '') {
                $this->recorder->distinct('auth.login_accounts', $user::class.'|'.$id);
            }

            if (self::privileged($user, Config::privilegedRoles())) {
                $this->recorder->count('auth.login_privileged');
            }
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }

    /**
     * Lockouts, reset links sent, passwords reset and failed two-factor
     * challenges are plain counts; the event itself is not read.
     */
    public function count(string $key): void
    {
        try {
            $this->recorder->count($key);
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }

    /**
     * The account a failed sign-in named: the first credential that is not
     * a password and holds a string (email, username, whatever the app signs
     * in with), trimmed and lowercased so case variants are one account.
     *
     * @param  mixed  $credentials
     */
    private static function accountIn($credentials): ?string
    {
        if (! is_array($credentials)) {
            return null;
        }

        foreach ($credentials as $field => $value) {
            if (str_contains(strtolower((string) $field), 'password') || ! is_string($value)) {
                continue;
            }

            $account = mb_strtolower(trim($value));

            if ($account !== '') {
                return $account;
            }
        }

        return null;
    }

    private function address(): ?string
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $ip = $this->app->make('request')->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Whether the user holds any of the privileged roles: spatie's
     * hasAnyRole when the user has it, else a `role` or `roles` attribute
     * (a name, an enum, or a list of names, enums or role objects with a
     * name), else not privileged.
     *
     * @param  list<string>  $roles
     */
    private static function privileged(Authenticatable $user, array $roles): bool
    {
        if ($roles === []) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole')) {
            return (bool) $user->hasAnyRole($roles);
        }

        $held = [];

        foreach (['role', 'roles'] as $attribute) {
            foreach (self::names(data_get($user, $attribute)) as $name) {
                $held[] = $name;
            }
        }

        $wanted = array_map('mb_strtolower', $roles);

        return array_intersect(array_map('mb_strtolower', $held), $wanted) !== [];
    }

    /**
     * Role names in an attribute value.
     *
     * @return list<string>
     */
    private static function names(mixed $value, bool $nested = false): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if ($value instanceof BackedEnum) {
            return [(string) $value->value];
        }

        if ($value instanceof UnitEnum) {
            return [$value->name];
        }

        // A list of roles, one level deep: each a name, an enum or a role
        // with a name.
        if (! $nested && is_iterable($value)) {
            $names = [];

            foreach ($value as $role) {
                foreach (self::names($role, true) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        // One role with a name, such as a related role model.
        if (is_object($value) || is_array($value)) {
            $name = data_get($value, 'name');

            return is_string($name) ? [$name] : [];
        }

        return [];
    }
}
