<?php

declare(strict_types=1);

namespace Laravel\Fortify\Events;

/*
 * Stand-in for Fortify's two-factor failure event, which the package listens
 * for only when the class exists. Required by the one test that needs it;
 * declared only when Fortify itself is not installed.
 */

if (! class_exists(TwoFactorAuthenticationFailed::class)) {
    class TwoFactorAuthenticationFailed
    {
        public function __construct(public mixed $user = null) {}
    }
}
