<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Overwatch;
use Arisultima\OverwatchAgent\Recording\DistinctSets;
use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Minute;
use Arisultima\OverwatchAgent\Tests\Fixtures\Role;
use Arisultima\OverwatchAgent\Tests\Fixtures\RoleUser;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * Sizes of a distinct set in this instance's current minute.
 *
 * @return array{distinct: int, max: int, capped: bool}
 */
function setNow(string $set): array
{
    return app(DistinctSets::class)->sizes(Instance::name(), Minute::current(), $set);
}

/**
 * Make the current request come from this address.
 */
function fromAddress(string $ip): void
{
    app()->instance('request', Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]));
}

/**
 * A failed sign-in naming this email from this address; with a user when the
 * account exists.
 */
function failSignIn(string $email, string $ip, bool $known = true): void
{
    fromAddress($ip);

    event(new Failed('web', $known ? new GenericUser(['id' => 7]) : null, [
        'email' => $email,
        'password' => 'secret',
    ]));
}

/**
 * The failed sign-ins of the first test: 3 for one account from one
 * address (in mixed case once), 1 for another, 1 for an unknown account.
 */
function failSignInBurst(): void
{
    failSignIn('a@example.com', '198.51.100.1');
    failSignIn('A@Example.com', '198.51.100.1');
    failSignIn('a@example.com', '198.51.100.1');
    failSignIn('b@example.com', '198.51.100.2');
    failSignIn('c@example.com', '198.51.100.2', known: false);
}

it('counts failed logins with distinct accounts and sources', function (): void {
    failSignInBurst();

    $counted = countedNow();
    $accounts = setNow('auth.login_failed_accounts');
    $sources = setNow('auth.login_failed_sources');

    expect($counted['auth.login_failed'])->toBe(5)
        ->and($counted['auth.login_failed_unknown'])->toBe(1)
        ->and($accounts['distinct'])->toBe(3)
        ->and($sources['distinct'])->toBe(2)
        ->and($sources['max'])->toBe(3)
        ->and($accounts['max'])->toBe(3);
});

it('never stores an email or address', function (): void {
    failSignInBurst();

    expect(setNow('auth.login_failed_accounts')['distinct'])->toBe(3);

    foreach (['a@example.com', 'A@Example.com', 'b@example.com', 'c@example.com', '198.51.100.1', '198.51.100.2'] as $identifier) {
        expect(arrayStoreHolds($identifier))->toBeFalse();
    }
});

it('takes the account from the first credential that is not a password', function (): void {
    fromAddress('198.51.100.1');

    event(new Failed('web', null, ['password' => 'secret', 'username' => 'Alice']));
    event(new Failed('web', null, ['username' => 'alice', 'password' => 'other']));
    event(new Failed('web', null, ['password' => 'secret']));

    expect(countedNow()['auth.login_failed'])->toBe(3)
        ->and(setNow('auth.login_failed_accounts'))->toBe(['distinct' => 1, 'max' => 2, 'capped' => false]);
});

it('counts sign-ins and the different accounts', function (): void {
    event(new Login('web', new GenericUser(['id' => 1]), false));
    event(new Login('web', new GenericUser(['id' => 1]), false));
    event(new Login('web', new GenericUser(['id' => 2]), true));

    $counted = countedNow();

    expect($counted['auth.login'])->toBe(3)
        ->and($counted)->not->toHaveKey('auth.login_privileged')
        ->and(setNow('auth.login_accounts'))->toBe(['distinct' => 2, 'max' => 2, 'capped' => false]);
});

it('counts privileged sign-ins', function (): void {
    event(new Login('web', new RoleUser(1, ['superadmin']), false));
    event(new Login('web', new RoleUser(2, ['editor']), false));

    expect(countedNow()['auth.login'])->toBe(2)
        ->and(countedNow()['auth.login_privileged'])->toBe(1);
});

it('reads a role or roles attribute when there is no hasAnyRole', function (): void {
    $users = [
        new GenericUser(['id' => 1, 'role' => 'admin']),
        new GenericUser(['id' => 2, 'role' => Role::Admin]),
        new GenericUser(['id' => 3, 'roles' => ['editor', 'root']]),
        new GenericUser(['id' => 4, 'roles' => collect([(object) ['name' => 'superadmin']])]),
        new GenericUser(['id' => 5, 'role' => 'editor']),
        new GenericUser(['id' => 6, 'roles' => [Role::Viewer]]),
        new GenericUser(['id' => 7]),
        new GenericUser(['id' => 8, 'role' => (object) ['name' => 'root']]),
    ];

    foreach ($users as $user) {
        event(new Login('web', $user, false));
    }

    expect(countedNow()['auth.login'])->toBe(8)
        ->and(countedNow()['auth.login_privileged'])->toBe(5);
});

it('counts privileged roles from config', function (): void {
    config(['overwatch.privileged_roles' => ['editor']]);

    event(new Login('web', new RoleUser(1, ['superadmin']), false));
    event(new Login('web', new RoleUser(2, ['editor']), false));

    expect(countedNow()['auth.login_privileged'])->toBe(1);
});

// Runs before the Fortify stand-in is loaded, so on its own (or first in the
// process) Fortify is absent here as well as the user model and its table.
it('boots without Fortify, spatie or a users table', function (): void {
    $this->bootWith(['user_model' => 'App\Models\NoSuchUser']);
    $this->freezeTime();

    event(new Login('web', new GenericUser(['id' => 1]), false));

    expect(countedNow())->toBe(['auth.login' => 1]);
});

it('counts lockouts, resets and 2FA failures through events and hooks', function (): void {
    require_once __DIR__.'/../Fixtures/FortifyEvents.php';

    // The provider decides on the Fortify listener while it boots.
    $this->bootWith([]);
    $this->freezeTime();

    $user = new GenericUser(['id' => 1]);

    event(new Lockout(Request::create('/login', 'POST')));
    event(new PasswordReset($user));
    event(new \Laravel\Fortify\Events\TwoFactorAuthenticationFailed($user));

    if (class_exists(PasswordResetLinkSent::class)) {
        event(new PasswordResetLinkSent($user));
    }

    Overwatch::twoFactorFailed();
    Overwatch::passwordResetRequested();
    Overwatch::privilegeChanged();
    Overwatch::impersonated();

    $counted = countedNow();

    expect($counted['auth.lockouts'])->toBe(1)
        ->and($counted['auth.password_reset'])->toBe(1)
        ->and($counted['auth.two_factor_failed'])->toBe(2)
        ->and($counted['auth.password_reset_requested'])->toBe(class_exists(PasswordResetLinkSent::class) ? 2 : 1)
        ->and($counted['auth.privilege_changes'])->toBe(1)
        ->and($counted['auth.impersonations'])->toBe(1);
});

it('counts nothing when disabled, from events or hooks', function (): void {
    $this->bootWith(['token' => null]);
    $this->freezeTime();

    failSignIn('a@example.com', '198.51.100.1');
    event(new Login('web', new RoleUser(1, ['superadmin']), false));
    Overwatch::twoFactorFailed();
    Overwatch::passwordResetRequested();
    Overwatch::privilegeChanged();
    Overwatch::impersonated();

    expect(countedNow())->toBe([])
        ->and(setNow('auth.login_failed_accounts')['distinct'])->toBe(0);
});

it('never throws from a sign-in listener', function (): void {
    $user = new class(['id' => 1]) extends GenericUser
    {
        public function hasAnyRole(mixed ...$roles): bool
        {
            throw new RuntimeException('roles table missing');
        }
    };

    event(new Login('web', $user, false));
    event(new Failed('web', null, ['email' => ['not', 'a', 'string'], 'password' => 'x']));

    expect(countedNow()['auth.login'])->toBe(1)
        ->and(countedNow()['auth.login_failed'])->toBe(1);
});
