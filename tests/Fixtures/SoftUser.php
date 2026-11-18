<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A user model that soft deletes, on Testbench's users table with a
 * deleted_at column added by the test.
 */
final class SoftUser extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];
}
