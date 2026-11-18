<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * The one lock the cache drivers use for a read-modify-write.
 *
 * @internal
 */
final class Locks
{
    /**
     * Run under a one-second lock, waiting at most a second for it. A lock
     * still busy after that drops this one write rather than hold up the
     * app. A store without locks runs the write unlocked.
     *
     * The lock lives 1 s, the same as the wait. On a store slower than a
     * second per get and put, the lock can expire mid-write and a second
     * writer overlap it, losing one update. Accepted: the alternative is a
     * longer hold on the request path.
     */
    public static function run(Repository $cache, string $name, Closure $fn): void
    {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            $fn();

            return;
        }

        try {
            $store->lock($name, 1)->betweenBlockedAttemptsSleepFor(20)->block(1, $fn);
        } catch (LockTimeoutException) {
            // Dropped on purpose; see above.
        }
    }
}
