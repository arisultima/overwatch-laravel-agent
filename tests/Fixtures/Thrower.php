<?php

declare(strict_types=1);

namespace OverwatchFixtures;

/*
 * Throw sites for the fingerprint tests. ExceptionRecorderTest points the
 * app's base path at the package root, so this file is in-app and the
 * framework under vendor/ is not.
 */

function throwHere(): never
{
    throw new \RuntimeException('user bob@example.com');
}

function callVendor(): mixed
{
    return app()->make('Overwatch\Fixtures\Missing');
}
