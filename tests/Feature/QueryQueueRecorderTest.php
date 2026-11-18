<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\QueueRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class PassingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

final class FailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('card 4111 1111 1111 1111 declined');
    }
}

final class SlowFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        usleep(15_000);

        throw new RuntimeException('timed out');
    }
}

final class SelfFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $this->fail(new RuntimeException('gave up'));
    }
}

/**
 * Buckets that run a slow query of their own on every write, as a database
 * cache store on a slow database would.
 */
final class QueryingBuckets implements Buckets
{
    public int $writes = 0;

    public function increment(string $instance, string $minute, string $key, int $by = 1): void
    {
        $this->writes++;

        event(new QueryExecuted('update cache set value = value + 1', [], 900.0, DB::connection()));
    }

    public function max(string $instance, string $minute, string $key, int $value): void {}

    public function read(string $instance, array $minutes): array
    {
        return [];
    }

    public function forget(string $instance, array $minutes): void {}
}

beforeEach(function (): void {
    $this->freezeTime();
});

it('counts slow queries without reading the SQL', function (): void {
    event(new QueryExecuted(
        "select * from users where email = 'secret@example.com'",
        ['secret@example.com'],
        600.0,
        DB::connection(),
    ));

    expect(countedNow())->toBe(['db.slow_queries' => 1])
        ->and(arrayStoreHolds('secret@example.com'))->toBeFalse();
});

it('reads the slow threshold once', function (): void {
    config(['overwatch.slow_query_ms' => 100]);
    event(new QueryExecuted('select 1', [], 150.0, DB::connection()));

    config(['overwatch.slow_query_ms' => 1000]);
    event(new QueryExecuted('select 1', [], 150.0, DB::connection()));

    expect(countedNow())->toBe(['db.slow_queries' => 2]);
});

it('counts a query at the threshold but not under it', function (): void {
    event(new QueryExecuted('select 1', [], 499.9, DB::connection()));
    event(new QueryExecuted('select 1', [], 500.0, DB::connection()));

    expect(countedNow())->toBe(['db.slow_queries' => 1]);
});

it('does not count its own writes as slow queries', function (): void {
    $buckets = new QueryingBuckets;
    app()->instance(Buckets::class, $buckets);

    event(new QueryExecuted('select sleep(1)', [], 900.0, DB::connection()));

    // One write for the app's query; the write's own query is not counted.
    expect($buckets->writes)->toBe(1);
});

/**
 * Run these jobs through a real worker on the database queue, on queue
 * `mail`: the sync driver names every queue "sync", whatever it was given.
 */
function workOnMail(array $jobs, int $tries = 1): void
{
    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
        'queue.failed.driver' => 'null',
    ]);

    test()->loadLaravelMigrations(['--database' => 'testing']);

    foreach ($jobs as $job) {
        dispatch($job)->onConnection('database')->onQueue('mail');
    }

    test()->artisan('queue:work', [
        'connection' => 'database',
        '--queue' => 'mail',
        '--stop-when-empty' => true,
        '--tries' => $tries,
        '--sleep' => 0,
    ])->assertExitCode(0);
}

it('counts processed and failed jobs per queue', function (): void {
    workOnMail([new PassingJob, new FailingJob]);

    $counted = countedNow();

    expect($counted['jobs.processed'])->toBe(1)
        ->and($counted['jobs.failed'])->toBe(1)
        ->and($counted['queue.mail.processed'])->toBe(1)
        ->and($counted['queue.mail.failed'])->toBe(1)
        ->and($counted)->toHaveKey('queue.mail.slowest_ms')
        // The whole number with its spaces: a bare '4111' also turns up in
        // the store's expiry timestamps at some seconds (1795034111).
        ->and(arrayStoreHolds('4111 1111 1111 1111'))->toBeFalse()
        ->and(arrayStoreHolds('declined'))->toBeFalse();
});

it('counts a job that fails itself once, as failed', function (): void {
    workOnMail([new SelfFailingJob]);

    $counted = countedNow();

    expect($counted['jobs.failed'])->toBe(1)
        ->and($counted['queue.mail.failed'])->toBe(1)
        ->and($counted)->not->toHaveKey('jobs.processed')
        ->and($counted)->not->toHaveKey('queue.mail.processed');
});

it('counts sync jobs, on the queue the driver names', function (): void {
    PassingJob::dispatch()->onQueue('mail');

    try {
        FailingJob::dispatch()->onQueue('mail');
    } catch (RuntimeException) {
        // The sync queue throws the job's exception back to the dispatcher.
    }

    $counted = countedNow();

    expect($counted['jobs.processed'])->toBe(1)
        ->and($counted['jobs.failed'])->toBe(1)
        ->and($counted['queue.sync.processed'])->toBe(1)
        ->and($counted['queue.sync.failed'])->toBe(1);
});

it('clears the start of a job released after an exception', function (): void {
    // Two tries: the first attempt is released, the second fails.
    workOnMail([new FailingJob], tries: 2);

    $counted = countedNow();

    expect($counted['jobs.failed'])->toBe(1)
        ->and($counted)->not->toHaveKey('jobs.processed')
        ->and(app(QueueRecorder::class)->pending())->toBe(0);
});

it('keeps the start through the exception event until the job settles', function (): void {
    $recorder = app(QueueRecorder::class);
    $job = new SyncJob(app(), '{}', 'custom', 'reports');
    $error = new RuntimeException('x');

    event(new JobProcessing('custom', $job));
    event(new JobExceptionOccurred('custom', $job, $error));

    // Not settled yet: the failure or the release that follows needs it.
    expect($recorder->pending())->toBe(1);

    event(new JobReleasedAfterException('custom', $job));

    expect($recorder->pending())->toBe(0);

    $released = new SyncJob(app(), '{}', 'custom', 'reports');

    event(new JobProcessing('custom', $released));
    $released->release();
    event(new JobExceptionOccurred('custom', $released, $error));

    expect($recorder->pending())->toBe(0);
});

it('times a failed sync job, though its exception event comes first', function (): void {
    try {
        SlowFailingJob::dispatch();
    } catch (RuntimeException) {
    }

    expect(countedNow()['queue.sync.slowest_ms'])->toBeGreaterThanOrEqual(15);
});

it('files a job without a queue name under default', function (): void {
    $job = new class(app(), '{}', 'custom', '') extends SyncJob
    {
        public function getQueue()
        {
            return '';
        }
    };

    event(new JobProcessing('custom', $job));
    event(new JobProcessed('custom', $job));

    expect(countedNow())->toHaveKey('queue.default.processed');
});

it('measures how long a job ran', function (): void {
    $job = new class(app(), '{}', 'custom', 'reports') extends SyncJob
    {
        public function getQueue()
        {
            return 'reports';
        }
    };

    event(new JobProcessing('custom', $job));
    usleep(15_000);
    event(new JobProcessed('custom', $job));

    expect(countedNow()['queue.reports.slowest_ms'])->toBeGreaterThanOrEqual(15)->toBeLessThan(1000);
});

it('registers the query and job listeners only when enabled', function (): void {
    expect(Event::hasListeners(QueryExecuted::class))->toBeTrue();

    $this->bootWith(['token' => null]);

    expect(Event::hasListeners(QueryExecuted::class))->toBeFalse()
        ->and(Event::hasListeners(JobProcessed::class))->toBeFalse()
        ->and(Event::hasListeners(\Illuminate\Queue\Events\JobFailed::class))->toBeFalse();
});
