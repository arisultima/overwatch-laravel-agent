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
use Arisultima\OverwatchAgent\Support\Fingerprint;
use Arisultima\OverwatchAgent\Support\Minute;
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
 * but every closed minute the server refused (and the dropped count it never
 * saw) joins the dropped count of the next report, and a warning is logged.
 * Anything else: nothing changes, and the minutes go with the next report,
 * or are counted as dropped once out of reach.
 *
 * The exit code is 0 whatever happens: a failed send never fails the
 * scheduler. What fails is logged by exception class only, at most once an
 * hour per class.
 */
final class ReportCommand extends Command
{
    /**
     * How long one run holds the report lock: 5 minutes, far longer than a
     * run takes (one send times out after 5 s), so a slow run never lets
     * another node send the same minutes. A run releases it as soon as it
     * is done.
     */
    public const LOCK_SECONDS = 300;

    public const UNAUTHORIZED_LOG_SECONDS = 3600;

    public const FAILURE_LOG_SECONDS = 3600;

    private const AT = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):00Z$/';

    protected $signature = 'overwatch:report';

    protected $description = 'Send the closed minutes to Overwatch (the scheduler runs this every minute)';

    public function handle(): int
    {
        try {
            if (Config::enabled()) {
                $this->locked(fn (bool $mark) => $this->report($mark));
            }
        } catch (Throwable $e) {
            // The minutes wait for the next run.
            self::failed($e, 'the report run');
        }

        return self::SUCCESS;
    }

    /**
     * Run under the report lock in the overwatch store, without waiting.
     *
     * This is the only lock: the schedule entry takes none (no
     * withoutOverlapping, no onOneServer), since those lock on the app's
     * default cache, which may be per machine, and a default store that is
     * down would stop the app's whole schedule. This one is the lock every
     * reporter of the overwatch store sees. When another run holds it, this
     * one does nothing.
     *
     * When taking the lock throws, the run still builds and sends, so the
     * checks tell Overwatch the store is in trouble, but marks nothing: an
     * unlocked run could overlap a locked one, send its minutes again or
     * move progress back. A store without locks (every store Laravel ships
     * has them; only a custom one may not) runs unlocked and marks.
     *
     * @param  Closure(bool): void  $run  given whether it may mark
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
        } catch (Throwable $e) {
            self::failed($e, 'taking the report lock');
            $run(false);

            return;
        }

        try {
            $run(true);
        } finally {
            self::release($lock);
        }
    }

    private function report(bool $mark): void
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
            } elseif ($result->status === null && $result->error !== null) {
                self::failedClass($result->error, 'sending the report');
            }

            return;
        }

        if ($result->isRejected()) {
            self::rejected($result);
        }

        if (! $mark) {
            return;
        }

        if ($result->isRejected()) {
            // Counted before the minutes are marked, so a run that dies in
            // between still reports the loss. The trade-off: a run that dies
            // between addDropped and markSent re-sends and re-counts the
            // refused minutes.
            Progress::addDropped(self::lost($report, $sent));
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
            } catch (Throwable $e) {
                // Behind the progress mark, so never sent again; they expire.
                self::failed($e, 'clearing sent counters');
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
     * What a refused report loses, as closed minutes not delivered, whether
     * or not they had counts (the meaning the builder gives expired
     * minutes): every minute it covered for each instance it would have
     * marked, plus the expired minutes it worked out itself. Its trimmed
     * minutes lie inside the covered ones, so they are not counted twice;
     * the dropped count already in the store stays there.
     *
     * An instance never sent before is counted from its first reported
     * minute (its trimmed minutes, older still, added on): the builder
     * covers it from 60 minutes back, but before its first count there is
     * no telling it existed, as the builder's own expiry rule holds.
     *
     * @param  array<string, mixed>  $report
     * @param  array<array-key, string>  $sent
     */
    private static function lost(array $report, array $sent): int
    {
        $instances = [];
        $truncated = 0;

        foreach (is_array($report['instances'] ?? null) ? $report['instances'] : [] as $instance) {
            if (is_array($instance) && is_string($instance['name'] ?? null)) {
                $instances[$instance['name']] = $instance;
                $truncated += is_int($instance['minutes_truncated'] ?? null) ? $instance['minutes_truncated'] : 0;
            }
        }

        $reported = self::reportedMinutes($report, $sent);
        $covered = 0;

        foreach ($sent as $name => $last) {
            $name = (string) $name;
            $end = Minute::parse($last)?->addMinute()->format(Minute::FORMAT);

            if ($end === null) {
                continue;
            }

            try {
                $from = Progress::lastSent($name);
            } catch (Throwable) {
                $from = null;
            }

            if ($from === null) {
                $first = $reported[$name][0] ?? null;
                $from = $first === null ? null : Minute::parse($first)?->subMinute()->format(Minute::FORMAT);
                $instanceTruncated = $instances[$name]['minutes_truncated'] ?? 0;
                $covered += is_int($instanceTruncated) ? $instanceTruncated : 0;

                if ($from === null) {
                    continue;
                }
            }

            $covered += count(Minute::range($from, $end));
        }

        $carried = is_int($report['dropped_minutes'] ?? null) ? $report['dropped_minutes'] : 0;

        return $covered + max(0, $carried - Progress::dropped() - $truncated);
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

    /**
     * Log what failed by exception class only (never the message, which may
     * hold a path, a host or a value), at most once an hour per class.
     */
    private static function failed(Throwable $e, string $doing): void
    {
        self::failedClass(class_basename(Fingerprint::className($e)), $doing);
    }

    /**
     * The hourly mark is kept in the overwatch store, else in the app's
     * default store when the overwatch one fails (it may be what failed).
     * With both down nothing is logged: the report's cache check says it.
     */
    private static function failedClass(string $class, string $doing): void
    {
        $key = Keys::failureLogged($class);
        $stores = [static fn () => Store::cache(), static fn () => app('cache')->store()];

        foreach ($stores as $store) {
            try {
                $first = $store()->add($key, true, self::FAILURE_LOG_SECONDS);
            } catch (Throwable) {
                continue;
            }

            if ($first) {
                try {
                    Log::warning(sprintf('Overwatch agent: %s failed with %s.', $doing, $class));
                } catch (Throwable) {
                    // Nowhere left to say it.
                }
            }

            return;
        }
    }

    private static function release(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (Throwable $e) {
            // It expires.
            self::failed($e, 'releasing the report lock');
        }
    }
}
