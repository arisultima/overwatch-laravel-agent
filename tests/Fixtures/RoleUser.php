<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests\Fixtures;

use Illuminate\Auth\GenericUser;

/**
 * A user with spatie's hasAnyRole(...$roles), answering from a plain list of
 * role names, so privileged sign-ins can be tested without spatie's tables.
 */
final class RoleUser extends GenericUser
{
    /**
     * @param  list<string>  $held
     */
    public function __construct(int $id, private readonly array $held)
    {
        parent::__construct(['id' => $id]);
    }

    public function hasAnyRole(mixed ...$roles): bool
    {
        $wanted = [];

        array_walk_recursive($roles, function (mixed $role) use (&$wanted): void {
            $wanted[] = (string) $role;
        });

        return array_intersect($wanted, $this->held) !== [];
    }
}
