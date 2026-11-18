<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The app's user model, on Testbench's users table.
 */
final class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
