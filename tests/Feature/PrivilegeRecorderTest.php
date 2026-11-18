<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Tests\Fixtures\SoftUser;
use Arisultima\OverwatchAgent\Tests\Fixtures\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Auth\User as FrameworkUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * spatie's four role and permission events in the installed version: with
 * an Event suffix from 7.0, without it in 6.15 to 6.x. Empty when neither.
 *
 * @return list<class-string>
 */
function spatieEvents(): array
{
    $classes = [];

    foreach (['RoleAttached', 'RoleDetached', 'PermissionAttached', 'PermissionDetached'] as $name) {
        foreach (['Spatie\Permission\Events\\'.$name.'Event', 'Spatie\Permission\Events\\'.$name] as $class) {
            if (class_exists($class)) {
                $classes[] = $class;

                break;
            }
        }
    }

    return count($classes) === 4 ? $classes : [];
}

it('counts role changes when spatie events exist', function (): void {
    $classes = spatieEvents();

    if ($classes === []) {
        $this->markTestSkipped('spatie/laravel-permission with events is not installed.');
    }

    $user = new User;

    foreach ($classes as $class) {
        event(new $class($user, [1]));
    }

    expect(countedNow())->toBe(['auth.privilege_changes' => 4]);
});

it('counts accounts created and deleted', function (): void {
    $this->bootWith(['user_model' => User::class]);
    $this->freezeTime();
    $this->loadLaravelMigrations();

    $first = User::create(['name' => 'One', 'email' => 'one@example.com', 'password' => 'x']);
    User::create(['name' => 'Two', 'email' => 'two@example.com', 'password' => 'x']);
    $first->delete();

    $counted = countedNow();

    expect($counted['auth.accounts_created'])->toBe(2)
        ->and($counted['auth.accounts_deleted'])->toBe(1)
        ->and(arrayStoreHolds('one@example.com'))->toBeFalse();
});

it('counts a soft-deleted account once, not again when force deleted', function (): void {
    $this->bootWith(['user_model' => SoftUser::class]);
    $this->freezeTime();
    $this->loadLaravelMigrations();

    Schema::table('users', fn (Blueprint $table) => $table->softDeletes());

    $trashed = SoftUser::create(['name' => 'One', 'email' => 'one@example.com', 'password' => 'x']);
    $live = SoftUser::create(['name' => 'Two', 'email' => 'two@example.com', 'password' => 'x']);

    $trashed->delete();

    expect(countedNow()['auth.accounts_deleted'])->toBe(1);

    $trashed->forceDelete();

    expect(countedNow()['auth.accounts_deleted'])->toBe(1)
        ->and(SoftUser::withTrashed()->count())->toBe(1);

    // Force deleting an account that was never trashed is its removal.
    $live->forceDelete();

    expect(countedNow()['auth.accounts_deleted'])->toBe(2)
        ->and(countedNow()['auth.accounts_created'])->toBe(2);
});

it('falls back to the auth provider model for accounts', function (): void {
    $this->loadLaravelMigrations();

    expect(config('auth.providers.users.model'))->toBe(FrameworkUser::class);

    FrameworkUser::forceCreate(['name' => 'One', 'email' => 'one@example.com', 'password' => 'x']);

    expect(countedNow())->toBe(['auth.accounts_created' => 1]);
});

it('counts gate denials and leaves the answer alone', function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));

    Gate::define('x', fn () => false);
    Gate::define('y', fn () => true);
    Gate::define('z', fn () => Response::deny('Not yours.'));

    expect(Gate::allows('x'))->toBeFalse()
        ->and(Gate::allows('y'))->toBeTrue()
        ->and(countedNow())->toBe(['auth.gate_denials' => 1]);

    expect(Gate::inspect('z')->denied())->toBeTrue()
        ->and(Gate::inspect('z')->message())->toBe('Not yours.')
        ->and(countedNow())->toBe(['auth.gate_denials' => 3]);
});

it('counts the final answer, after the app after callbacks', function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));

    // An app callback registered after the package's may still grant an
    // ability nothing else answered; that is not a denial.
    Gate::after(fn ($user, string $ability) => $ability === 'granted-late' ? true : null);

    expect(Gate::allows('granted-late'))->toBeTrue()
        ->and(Gate::allows('undefined-ability'))->toBeFalse()
        ->and(countedNow())->toBe(['auth.gate_denials' => 1]);
});

it('counts a guest denied an ability', function (): void {
    Gate::define('x', fn (GenericUser $user) => true);

    expect(Gate::allows('x'))->toBeFalse()
        ->and(countedNow())->toBe(['auth.gate_denials' => 1]);
});

it('registers no privilege or account listeners when disabled', function (): void {
    $this->bootWith(['token' => null, 'user_model' => User::class]);
    $this->freezeTime();
    $this->loadLaravelMigrations();

    User::create(['name' => 'One', 'email' => 'one@example.com', 'password' => 'x']);
    Gate::define('x', fn () => false);
    Gate::allows('x');

    foreach (spatieEvents() as $class) {
        event(new $class(new User, [1]));
    }

    expect(countedNow())->toBe([]);
});
