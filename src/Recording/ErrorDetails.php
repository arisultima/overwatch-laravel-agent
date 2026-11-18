<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Illuminate\Contracts\Cache\Repository;

/**
 * The side map from an error fingerprint to its class and location, which
 * the `error.{fingerprint}` counters leave out. Nothing else about an
 * exception is kept.
 */
final class ErrorDetails
{
    public function __construct(private readonly Repository $cache) {}

    /**
     * Written once a minute per fingerprint per process, with the bucket TTL,
     * so it outlives every bucket that counts the fingerprint.
     */
    public function describe(string $fingerprint, string $class, string $location): void
    {
        $this->cache->put(Keys::error($fingerprint), ['class' => $class, 'location' => $location], Buckets::TTL);
    }

    /**
     * The known fingerprints among these, mapped to their class and location.
     *
     * @param  list<string>  $fingerprints
     * @return array<string, array{class: string, location: string}>
     */
    public function of(array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $keys = [];

        foreach ($fingerprints as $fingerprint) {
            $keys[Keys::error($fingerprint)] = $fingerprint;
        }

        $details = [];

        foreach ($this->cache->getMultiple(array_keys($keys)) as $key => $value) {
            if (is_array($value) && is_string($value['class'] ?? null) && is_string($value['location'] ?? null)) {
                $details[$keys[$key]] = ['class' => $value['class'], 'location' => $value['location']];
            }
        }

        return $details;
    }
}
