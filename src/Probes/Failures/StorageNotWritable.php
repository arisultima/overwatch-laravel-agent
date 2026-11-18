<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes\Failures;

use RuntimeException;

/**
 * The storage/app directory cannot be written to. A probe reports only
 * this class name.
 *
 * @internal
 */
final class StorageNotWritable extends RuntimeException {}
