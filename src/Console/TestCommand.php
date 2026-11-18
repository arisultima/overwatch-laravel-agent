<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Console;

use Arisultima\OverwatchAgent\Config;
use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Report\Sender;
use Arisultima\OverwatchAgent\Report\SendResult;
use Arisultima\OverwatchAgent\Support\Fingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Builds the report the next scheduled run would send, prints it, sends it
 * once (unless --dry-run) and says what the answer means.
 *
 * It changes nothing the scheduled report relies on: no minute is marked
 * sent, no counter forgotten, the dropped count and the versions mark stay
 * as they are, so the next real report sends the same minutes. It does not
 * take the report lock either, so a real run is never kept waiting.
 *
 * Exits 0 only when Overwatch took the report (or, with --dry-run, when the
 * package is configured).
 */
final class TestCommand extends Command
{
    protected $signature = 'overwatch:test
        {--dry-run : Build and print the report without sending it}';

    protected $description = 'Send one report to Overwatch now and show the answer, without marking anything sent';

    private const PRINT_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $missing = Config::missing();

        foreach ($missing as $line) {
            $this->components->error($line);
        }

        if ($missing !== [] && ! $dryRun) {
            return self::FAILURE;
        }

        try {
            InstanceStamp::maybe();
            $report = $this->laravel->make(ReportBuilder::class)->build(Carbon::now())['report'];
            $json = json_encode($report, self::PRINT_FLAGS);
        } catch (Throwable $e) {
            $this->components->error('Could not build the report ('.self::className($e).').');

            return self::FAILURE;
        }

        $this->line($json);
        $this->newLine();

        if ($dryRun) {
            $this->components->info('Dry run: nothing was sent.');

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Sending it to '.self::endpoint().' once.');

        $result = $this->laravel->make(Sender::class)->send($report);

        if ($result->isAccepted()) {
            $this->components->info(self::status($result).': Overwatch took the report. Nothing was marked sent, so the next scheduled report carries these minutes too.');

            return self::SUCCESS;
        }

        $this->components->error(self::meaning($result));

        return self::FAILURE;
    }

    /**
     * What an answer other than 202 means, per the protocol's response
     * table, and what to do about it.
     */
    private static function meaning(SendResult $result): string
    {
        $status = $result->status;

        if ($result->isRejected()) {
            return sprintf(
                'Overwatch refused the report (HTTP %d).%s A resend cannot succeed: a scheduled run would mark these minutes sent and count them as dropped.',
                $status,
                $result->error === null ? '' : ' It said: '.rtrim($result->error, '.').'.',
            );
        }

        return match (true) {
            $status === null && $result->error !== null => sprintf(
                'No answer from Overwatch (%s). Check OVERWATCH_URL and that this server can reach it. Scheduled runs keep the minutes for 60 minutes.',
                $result->error,
            ),
            $status === null => 'The report could not be sent. Scheduled runs keep the minutes for 60 minutes.',
            $status === 401 => 'HTTP 401: Overwatch does not know this token, or it was revoked. Check OVERWATCH_TOKEN. Scheduled runs keep the minutes for 60 minutes.',
            $status === 429, $status === 503 => sprintf(
                'HTTP %d: Overwatch asked the app to slow down. Scheduled runs keep the minutes and send them next time.',
                $status,
            ),
            $status >= 300 && $status < 400 => sprintf(
                'Overwatch did not take the report (HTTP %d): it answered with a redirect, which the agent never follows. Set OVERWATCH_URL to the address it redirects to.',
                $status,
            ),
            $status >= 200 && $status < 300 => sprintf(
                'Overwatch did not take the report (HTTP %d): only 202 means taken. Check that OVERWATCH_URL points at Overwatch itself, not a page in front of it.',
                $status,
            ),
            default => sprintf(
                'Overwatch did not take the report (HTTP %d). Scheduled runs keep the minutes and send them next time.',
                $status,
            ),
        };
    }

    private static function status(SendResult $result): string
    {
        return 'HTTP '.($result->status ?? 'none');
    }

    /**
     * The endpoint without any user or password in the URL.
     */
    private static function endpoint(): string
    {
        $parts = parse_url(Config::url());

        if (! is_array($parts) || ! isset($parts['host'])) {
            return Config::url().Sender::PATH;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .rtrim($parts['path'] ?? '', '/')
            .Sender::PATH;
    }

    private static function className(Throwable $e): string
    {
        return class_basename(Fingerprint::className($e));
    }
}
