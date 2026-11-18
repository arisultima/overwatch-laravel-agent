<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes\Failures;

use RuntimeException;

/**
 * Horizon is installed and no master supervisor is running. A probe
 * reports only this class name.
 *
 * @internal
 */
final class HorizonInactive extends RuntimeException {}
