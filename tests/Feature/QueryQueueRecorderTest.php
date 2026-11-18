<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Recording\Buckets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
function workOnMail(object ...$jobs): void
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
        '--tries' => 1,
        '--sleep' => 0,
    ])->assertExitCode(0);
}

it('counts processed and failed jobs per queue', function (): void {
    workOnMail(new PassingJob, new FailingJob);

    $counted = countedNow();

    expect($counted['jobs.processed'])->toBe(1)
        ->and($counted['jobs.failed'])->toBe(1)
        ->and($counted['queue.mail.processed'])->toBe(1)
        ->and($counted['queue.mail.failed'])->toBe(1)
        ->and($counted)->toHaveKey('queue.mail.slowest_ms')
        ->and(arrayStoreHolds('4111'))->toBeFalse();
});

it('counts a job that fails itself once, as failed', function (): void {
    workOnMail(new SelfFailingJob);

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
