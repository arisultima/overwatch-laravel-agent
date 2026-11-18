<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

/**
 * Per-instance, per-minute counters.
 *
 * Counter keys are the schema's `counters` keys, `histogram.le_50` and the
 * other bands, `queue.{name}.processed|failed|slowest_ms`, and
 * `error.{fingerprint}` (whose class and location live in ErrorDetails).
 */
interface Buckets
{
    /**
     * Seconds a bucket lives: the 60 minutes a report may reach back, plus
     * a margin.
     */
    public const TTL = 3700;

    public function increment(string $instance, string $minute, string $key, int $by = 1): void;

    /**
     * Keep the larger of the stored value and this one (for slowest_ms).
     */
    public function max(string $instance, string $minute, string $key, int $value): void;

    /**
     * Every requested minute, mapped to its counters sorted by key; a minute
     * with nothing counted maps to an empty array.
     *
     * @param  list<string>  $minutes
     * @return array<string, array<string, int>>
     */
    public function read(string $instance, array $minutes): array;

    /**
     * @param  list<string>  $minutes
     */
    public function forget(string $instance, array $minutes): void;
}
