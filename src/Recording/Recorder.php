<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Arisultima\OverwatchAgent\Support\Instance;
use Arisultima\OverwatchAgent\Support\Minute;
use Illuminate\Contracts\Container\Container;

/**
 * The one way listeners record anything. Every call counts into this
 * instance's current minute and runs through the Guard, so it never throws
 * and does nothing while the store is paused. The store drivers are resolved
 * on first use, inside the Guard, so a store that cannot even be built is
 * swallowed like one that is down.
 */
final class Recorder
{
    /**
     * Request duration bands, upper bound in ms to histogram key. Anything
     * slower lands in histogram.gt_5000.
     */
    private const BANDS = [
        50 => 'histogram.le_50',
        100 => 'histogram.le_100',
        250 => 'histogram.le_250',
        500 => 'histogram.le_500',
        1000 => 'histogram.le_1000',
        2500 => 'histogram.le_2500',
        5000 => 'histogram.le_5000',
    ];

    private ?Buckets $buckets = null;

    private ?DistinctSets $sets = null;

    private ?ErrorDetails $details = null;

    /**
     * Fingerprints whose details this process wrote in the current minute.
     *
     * @var array<string, true>
     */
    private array $described = [];

    private ?string $describedMinute = null;

    public function __construct(private readonly Container $app) {}

    public function count(string $key, int $by = 1): void
    {
        Guard::quietly(function () use ($key, $by): void {
            $this->buckets()->increment(Instance::name(), Minute::current(), $key, $by);
        });
    }

    /**
     * Add an identifier (an address, an account) to a distinct set. Only its
     * keyed hash is stored.
     */
    public function distinct(string $set, string $identifier): void
    {
        Guard::quietly(function () use ($set, $identifier): void {
            $this->sets()->add(Instance::name(), Minute::current(), $set, $identifier);
        });
    }

    /**
     * Count one occurrence of an error group, and note its class and location
     * once a minute so the report can name it.
     */
    public function error(string $fingerprint, string $class, string $location): void
    {
        Guard::quietly(function () use ($fingerprint, $class, $location): void {
            $minute = Minute::current();

            $this->buckets()->increment(Instance::name(), $minute, 'error.'.$fingerprint);

            if ($this->describedMinute !== $minute) {
                $this->described = [];
                $this->describedMinute = $minute;
            }

            if (! isset($this->described[$fingerprint])) {
                $this->details()->describe($fingerprint, $class, $location);
                $this->described[$fingerprint] = true;
            }
        });
    }

    /**
     * One finished job on a named queue: processed or failed, and how long it
     * ran, for the queue's slowest_ms.
     */
    public function queue(string $name, bool $failed, int $ms): void
    {
        Guard::quietly(function () use ($name, $failed, $ms): void {
            $instance = Instance::name();
            $minute = Minute::current();
            $prefix = 'queue.'.$name.'.';

            $this->buckets()->increment($instance, $minute, $prefix.($failed ? 'failed' : 'processed'));
            $this->buckets()->max($instance, $minute, $prefix.'slowest_ms', max(0, $ms));
        });
    }

    /**
     * Count a request duration into its histogram band.
     */
    public function histogram(int $ms): void
    {
        $this->count(self::band($ms));
    }

    private static function band(int $ms): string
    {
        foreach (self::BANDS as $upTo => $key) {
            if ($ms <= $upTo) {
                return $key;
            }
        }

        return 'histogram.gt_5000';
    }

    private function buckets(): Buckets
    {
        return $this->buckets ??= $this->app->make(Buckets::class);
    }

    private function sets(): DistinctSets
    {
        return $this->sets ??= $this->app->make(DistinctSets::class);
    }

    private function details(): ErrorDetails
    {
        return $this->details ??= $this->app->make(ErrorDetails::class);
    }
}
