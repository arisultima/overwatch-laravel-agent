<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Arisultima\OverwatchAgent\Config;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Counts queries at or over the slow threshold. Only the time is read: never
 * the SQL, the bindings or the connection.
 */
final class QueryRecorder
{
    /**
     * Set while this recorder writes, so a write that is itself a slow query
     * (a database cache store on a slow database) is not counted, and cannot
     * start a loop of counts.
     */
    private bool $recording = false;

    public function __construct(private readonly Recorder $recorder) {}

    public function handle(QueryExecuted $event): void
    {
        if ($this->recording) {
            return;
        }

        $this->recording = true;

        try {
            if ($event->time >= Config::slowQueryMs()) {
                $this->recorder->count('db.slow_queries');
            }
        } catch (Throwable) {
            // Recording never throws into the app.
        } finally {
            $this->recording = false;
        }
    }
}
