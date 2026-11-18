<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Console;

use Arisultima\OverwatchAgent\Config;
use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Probes\Versions;
use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\Keys;
use Arisultima\OverwatchAgent\Report\Progress;
use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Report\Sender;
use Arisultima\OverwatchAgent\Report\SendResult;
use Arisultima\OverwatchAgent\Support\Store;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Run by the scheduler every minute: build one report from every instance's
 * closed minutes, send it once, and mark what the server took.
 *
 * Accepted (202): each instance's last minute is marked sent, its reported
 * counters forgotten, versions marked when they went, the dropped count
 * cleared. Rejected (422 or 413): the same, since a resend cannot succeed,
 * but the minutes the server refused (and the dropped count it never saw)
 * become the dropped count of the next report, and a warning is logged.
 * Anything else: nothing changes, and the minutes go with the next report,
 * or are counted as dropped once out of reach.
 *
 * The exit code is 0 whatever happens: a failed send never fails the
 * scheduler.
 */
final class ReportCommand extends Command
{
    /**
     * How long one run holds the report lock. A run gives up on the server
     * after 5 s, so this only matters when the process dies holding it.
     */
    public const LOCK_SECONDS = 55;

    public const UNAUTHORIZED_LOG_SECONDS = 3600;

    private const AT = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):00Z$/';

    protected $signature = 'overwatch:report';

    protected $description = 'Send the closed minutes to Overwatch (the scheduler runs this every minute)';

    public function handle(): int
    {
        try {
            if (Config::enabled()) {
                $this->locked(fn () => $this->report());
            }
        } catch (Throwable) {
            // The minutes wait for the next run.
        }

        return self::SUCCESS;
    }

    /**
     * Run under the report lock in the overwatch store, without waiting.
     *
     * The schedule's withoutOverlapping and onOneServer lock on the app's
     * default cache, which may be per machine while the overwatch store is
     * shared; this lock is the one every reporter of the store sees. When
     * another run holds it, this one does nothing. When the store cannot
     * be reached the run goes ahead unlocked: nothing can be marked then,
     * and the checks still tell Overwatch the store is down.
     */
    private function locked(Closure $run): void
    {
        $lock = null;

        try {
            $store = Store::cache()->getStore();

            if ($store instanceof LockProvider) {
                $lock = $store->lock(Keys::reportLock(), self::LOCK_SECONDS);

                if (! $lock->get()) {
                    return;
                }
            }
        } catch (Throwable) {
            $lock = null;
        }

        try {
            $run();
        } finally {
            self::release($lock);
        }
    }

    private function report(): void
    {
        InstanceStamp::maybe();

        $built = $this->laravel->make(ReportBuilder::class)->build(Carbon::now());
        $report = $built['report'];
        $sent = $built['sent'];

        $result = $this->laravel->make(Sender::class)->send($report);

        $this->line('Overwatch: '.$result->kind.' (HTTP '.($result->status ?? 'none').').', null, OutputInterface::VERBOSITY_VERBOSE);

        if ($result->isKeep()) {
            if ($result->status === 401) {
                self::unauthorized();
            }

            return;
        }

        if ($result->isRejected()) {
            self::rejected($result);

            // Counted before the minutes are marked, so a run that dies in
            // between still reports the loss.
            Progress::addDropped(self::lost($report));
        }

        $this->markSent($report, $sent);

        if ($result->isAccepted()) {
            Progress::clearDropped();
        }
    }

    /**
     * Mark each instance's last minute sent, then forget the counters the
     * report carried and remember the versions if it carried them. Marking
     * comes first: a counter left behind expires, a minute sent twice
     * counts twice.
     *
     * @param  array<string, mixed>  $report
     * @param  array<array-key, string>  $sent
     */
    private function markSent(array $report, array $sent): void
    {
        foreach ($sent as $instance => $minute) {
            Progress::markSent($instance, $minute);
        }

        $buckets = null;

        foreach (self::reportedMinutes($report, $sent) as $instance => $minutes) {
            try {
                $buckets ??= $this->laravel->make(Buckets::class);
                // An all-digit name came back as an int key.
                $buckets->forget((string) $instance, $minutes);
            } catch (Throwable) {
                // Behind the progress mark, so never sent again; they expire.
            }
        }

        $hash = $report['versions']['lock_hash'] ?? null;

        if (is_string($hash) && $hash !== '') {
            Versions::markSent($hash);
        }
    }

    /**
     * The minutes with counts the report carried, per instance whose
     * progress moves, as YmdHi.
     *
     * @param  array<string, mixed>  $report
     * @param  array<array-key, string>  $sent
     * @return array<array-key, list<string>>
     */
    private static function reportedMinutes(array $report, array $sent): array
    {
        $marked = array_map('strval', array_keys($sent));
        $reported = [];

        foreach (is_array($report['instances'] ?? null) ? $report['instances'] : [] as $instance) {
            $name = is_array($instance) ? ($instance['name'] ?? null) : null;

            if (! is_string($name) || ! in_array($name, $marked, true)) {
                continue;
            }

            foreach (is_array($instance['minutes'] ?? null) ? $instance['minutes'] : [] as $minute) {
                if (is_string($minute['at'] ?? null) && preg_match(self::AT, $minute['at'], $m) === 1) {
                    $reported[$name][] = $m[1].$m[2].$m[3].$m[4].$m[5];
                }
            }
        }

        return $reported;
    }

    /**
     * What a refused report loses: its minutes with counts, and the dropped
     * minutes it was carrying beyond those already kept in the store (the
     * expired and trimmed ones it worked out itself).
     *
     * @param  array<string, mixed>  $report
     */
    private static function lost(array $report): int
    {
        $refused = 0;

        foreach (is_array($report['instances'] ?? null) ? $report['instances'] : [] as $instance) {
            $refused += is_array($instance['minutes'] ?? null) ? count($instance['minutes']) : 0;
        }

        $carried = is_int($report['dropped_minutes'] ?? null) ? $report['dropped_minutes'] : 0;

        return $refused + max(0, $carried - Progress::dropped());
    }

    private static function rejected(SendResult $result): void
    {
        try {
            Log::warning(sprintf(
                'Overwatch refused the app report (HTTP %d)%s. Its minutes are marked sent and counted as dropped.',
                $result->status,
                $result->error === null ? '' : ': '.$result->error,
            ));
        } catch (Throwable) {
            // Logging must not stop the minutes being marked.
        }
    }

    /**
     * Once an hour across every reporter of the store: a wrong token stays
     * wrong until someone changes it.
     */
    private static function unauthorized(): void
    {
        try {
            if (Store::cache()->add(Keys::unauthorizedLogged(), true, self::UNAUTHORIZED_LOG_SECONDS)) {
                Log::warning('Overwatch refused the app token (HTTP 401). Check OVERWATCH_TOKEN; unsent minutes are kept for 60 minutes.');
            }
        } catch (Throwable) {
            // Said next hour.
        }
    }

    private static function release(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (Throwable) {
            // It expires.
        }
    }
}
