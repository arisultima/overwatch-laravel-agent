<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes;

use Arisultima\OverwatchAgent\Config;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * How many jobs wait on each configured queue of the default connection, and
 * how long the oldest has waited. One size call per queue, plus one indexed
 * query or one list read for the age on the database and Redis drivers.
 */
final class QueueDepths
{
    public const MAX_ROWS = 20;

    /**
     * Drivers that keep no queue to size: their depths are null.
     */
    private const UNSIZED = ['sync', 'null', 'deferred', 'background'];

    /**
     * One row per configured queue name, at most 20. A queue that cannot be
     * sized right now gives nulls rather than failing the rest.
     *
     * @return array{rows: list<array{connection: string, name: string, pending: ?int, oldest_pending_seconds: ?int}>, truncated: int}
     */
    public static function rows(): array
    {
        $names = Config::queues();
        $connection = Config::queueConnection();
        $rows = [];

        foreach (array_slice($names, 0, self::MAX_ROWS) as $name) {
            try {
                $depth = self::measure($name);
            } catch (Throwable) {
                $depth = ['pending' => null, 'oldest_pending_seconds' => null];
            }

            $rows[] = ['connection' => $connection, 'name' => $name, ...$depth];
        }

        return ['rows' => $rows, 'truncated' => max(0, count($names) - self::MAX_ROWS)];
    }

    /**
     * The default connection's driver name.
     */
    public static function driver(): string
    {
        $connection = Config::queueConnection();
        $driver = config('queue.connections.'.$connection.'.driver');

        return is_string($driver) && $driver !== '' ? $driver : $connection;
    }

    /**
     * The depth and the oldest waiting job's age of one queue on the default
     * connection. Throws what the driver throws; the health probe reports it.
     *
     * @return array{pending: ?int, oldest_pending_seconds: ?int}
     */
    public static function measure(string $name): array
    {
        $driver = self::driver();

        if (in_array($driver, self::UNSIZED, true)) {
            return ['pending' => null, 'oldest_pending_seconds' => null];
        }

        $queue = app('queue')->connection(Config::queueConnection());

        return [
            'pending' => (int) $queue->size($name),
            'oldest_pending_seconds' => in_array($driver, ['database', 'redis'], true) ? self::oldest($queue, $name) : null,
        ];
    }

    /**
     * Seconds since the oldest job ready to run became available, or null
     * when none waits or the driver cannot tell.
     */
    private static function oldest(Queue $queue, string $name): ?int
    {
        $created = self::oldestCreatedAt($queue, $name);

        return is_numeric($created) ? max(0, Carbon::now()->getTimestamp() - (int) $created) : null;
    }

    /**
     * The framework answers this since Laravel 12; the fallbacks run the
     * same reads on older releases.
     */
    private static function oldestCreatedAt(Queue $queue, string $name): mixed
    {
        if (method_exists($queue, 'creationTimeOfOldestPendingJob')) {
            return $queue->creationTimeOfOldestPendingJob($name);
        }

        if ($queue instanceof DatabaseQueue) {
            $table = config('queue.connections.'.Config::queueConnection().'.table', 'jobs');

            return $queue->getDatabase()->table(is_string($table) ? $table : 'jobs')
                ->where('queue', $name)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', Carbon::now()->getTimestamp())
                ->min('available_at');
        }

        if ($queue instanceof RedisQueue) {
            // The head of the list is the next job out. Only its creation
            // time is read; the payload goes nowhere.
            $payload = $queue->getConnection()->lindex($queue->getQueue($name), 0);
            $data = is_string($payload) ? json_decode($payload, true) : null;

            return is_array($data) ? ($data['createdAt'] ?? null) : null;
        }

        return null;
    }
}
