<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Distinct sets on any cache store: each set is one cached array of hash to
 * count, rewritten under a one-second lock. A lock still busy after a
 * second drops that one member rather than hold up the request.
 */
final class LockedDistinctSets implements DistinctSets
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $appKey,
    ) {}

    public static function lockKey(string $instance, string $minute, string $set): string
    {
        return Keys::set($instance, $minute, $set).':lock';
    }

    public function add(string $instance, string $minute, string $set, string $identifier): void
    {
        // Without an app key the hash would be a plain digest, which a list
        // of every IPv4 address reverses. Better to record nothing.
        if ($this->appKey === '') {
            return;
        }

        $hash = hash_hmac('sha256', $identifier, $this->appKey);
        $key = Keys::set($instance, $minute, $set);

        $this->locked(self::lockKey($instance, $minute, $set), function () use ($key, $hash): void {
            $stored = $this->stored($key);

            if (isset($stored['members'][$hash])) {
                $stored['members'][$hash]++;
            } elseif (count($stored['members']) < self::CAP) {
                $stored['members'][$hash] = 1;
            } elseif ($stored['capped']) {
                return;
            } else {
                $stored['capped'] = true;
            }

            $this->cache->put($key, $stored, Buckets::TTL);
        });
    }

    public function sizes(string $instance, string $minute, string $set): array
    {
        $stored = $this->stored(Keys::set($instance, $minute, $set));

        return [
            'distinct' => min(count($stored['members']), self::CAP),
            'max' => $stored['members'] === [] ? 0 : (int) max($stored['members']),
            'capped' => $stored['capped'],
        ];
    }

    /**
     * @return array{members: array<string, int>, capped: bool}
     */
    private function stored(string $key): array
    {
        $stored = $this->cache->get($key);

        return [
            'members' => is_array($stored) && is_array($stored['members'] ?? null) ? $stored['members'] : [],
            'capped' => is_array($stored) && ($stored['capped'] ?? false) === true,
        ];
    }

    /**
     * Run under a one-second lock, waiting at most a second for it.
     */
    private function locked(string $name, Closure $fn): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            $fn();

            return;
        }

        try {
            $store->lock($name, 1)->betweenBlockedAttemptsSleepFor(20)->block(1, $fn);
        } catch (LockTimeoutException) {
            // Dropped on purpose; see the class comment.
        }
    }
}
