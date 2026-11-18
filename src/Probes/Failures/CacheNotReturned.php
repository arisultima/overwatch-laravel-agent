<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes\Failures;

use RuntimeException;

/**
 * The cache accepted a write and did not return it, so everything looks
 * cached and nothing is. A probe reports only this class name.
 *
 * @internal
 */
final class CacheNotReturned extends RuntimeException {}
