<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Throwable;

/**
 * Counts finished jobs, per queue and in total, and how long each ran. Only
 * the queue name and the timing are read; never the payload or the
 * exception.
 *
 * Bound as a singleton, so the start noted on JobProcessing is there on
 * JobProcessed or JobFailed.
 */
final class QueueRecorder
{
    /**
     * Starts kept at most, as a backstop: every path that ends an attempt
     * clears its start, but a path a future framework adds must not grow
     * the map forever in a long-running worker.
     */
    private const MAX_STARTED = 100;

    /**
     * hrtime in ns per running job. Keyed by the job object, which the worker
     * passes to all three events: the sync driver gives every job the same
     * empty job id, and a sync job dispatched inside another must not take
     * over its start.
     *
     * @var array<int, int|float>
     */
    private array $started = [];

    public function __construct(private readonly Recorder $recorder) {}

    public function processing(JobProcessing $event): void
    {
        $key = spl_object_id($event->job);

        unset($this->started[$key]);
        $this->started[$key] = hrtime(true);

        if (count($this->started) > self::MAX_STARTED) {
            unset($this->started[array_key_first($this->started)]);
        }
    }

    /**
     * A job that failed itself was counted on JobFailed, and a released job
     * will run again, so neither counts as processed.
     */
    public function processed(JobProcessed $event): void
    {
        try {
            $job = $event->job;

            if ($job->hasFailed() || $job->isReleased()) {
                unset($this->started[spl_object_id($job)]);

                return;
            }

            $this->finish($job, false);
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }

    public function failed(JobFailed $event): void
    {
        try {
            $this->finish($event->job, true);
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }

    /**
     * The worker fails a job before it raises this event, and releases it
     * after (JobReleasedAfterException); the sync driver raises it before it
     * fails the job. So the start is cleared here only when the attempt is
     * already settled (deleted, released or failed), and is otherwise left
     * for the failure, which needs it, or the release, which clears it.
     */
    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        try {
            $job = $event->job;

            if ($job->isDeleted() || $job->isReleased() || $job->hasFailed()) {
                unset($this->started[spl_object_id($job)]);
            }
        } catch (Throwable) {
            // Recording never throws into the app.
        }
    }

    /**
     * Released to run again: this attempt is not counted.
     */
    public function released(JobReleasedAfterException $event): void
    {
        unset($this->started[spl_object_id($event->job)]);
    }

    /**
     * How many job starts are held. For tests.
     *
     * @internal
     */
    public function pending(): int
    {
        return count($this->started);
    }

    private function finish(Job $job, bool $failed): void
    {
        $key = spl_object_id($job);
        $started = $this->started[$key] ?? null;
        unset($this->started[$key]);

        $queue = (string) $job->getQueue();

        $ms = $started === null ? 0 : (int) ceil((hrtime(true) - $started) / 1_000_000);

        $this->recorder->count($failed ? 'jobs.failed' : 'jobs.processed');
        $this->recorder->queue($queue === '' ? 'default' : $queue, $failed, $ms);
    }
}
