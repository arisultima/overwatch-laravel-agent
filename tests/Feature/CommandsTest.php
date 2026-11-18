<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Probes\Versions;
use Arisultima\OverwatchAgent\Recording\Buckets;
use Arisultima\OverwatchAgent\Recording\Keys;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Arisultima\OverwatchAgent\Report\Progress;
use Arisultima\OverwatchAgent\Support\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Run an overwatch command; returns the exit code and what it printed.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runOverwatch(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

/**
 * The pretty JSON report a command printed, decoded.
 *
 * @return array<string, mixed>
 */
function printedReport(string $output): array
{
    expect(preg_match('/^\{\n.*?^\}$/ms', $output, $match))->toBe(1);

    return json_decode($match[0], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The report the fake Overwatch received last, decoded.
 *
 * @return array<string, mixed>
 */
function receivedReport(): array
{
    /** @var Request $request */
    $request = Http::recorded()->last()[0];

    return json_decode((string) gzdecode($request->body()), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Counts of web-1 at 13:05, sent by the next report at 13:06:15, with 4
 * dropped minutes already waiting.
 */
function oneClosedMinute(): void
{
    as_instance('web-1');
    at('13:05:30');
    app(Recorder::class)->count('http.requests', 3);
    Progress::addDropped(4);
    at('13:06:15');
}

/**
 * Nothing the real report would mark has moved: progress, counters, the
 * dropped count and the versions.
 */
function nothingMarked(): void
{
    expect(Progress::lastSent('web-1'))->toBeNull()
        ->and(app(Buckets::class)->read('web-1', ['202611181305'])['202611181305'])->toBe(['http.requests' => 3])
        ->and(Progress::dropped())->toBe(4)
        ->and(Versions::due())->toBeTrue();
}

function envPath(): string
{
    return base_path('.env');
}

afterEach(function (): void {
    @unlink(config_path('overwatch.php'));
});

it('install prints the env lines and leaves .env alone', function (): void {
    $before = file_exists(envPath()) ? file_get_contents(envPath()) : null;
    file_put_contents(envPath(), "APP_NAME=Shop\nOVERWATCH_TOKEN=keep-me\n");

    try {
        @unlink(config_path('overwatch.php'));

        [$code, $output] = runOverwatch('overwatch:install');

        expect($code)->toBe(0)
            ->and($output)->toContain("OVERWATCH_URL=\n")
            ->and($output)->toContain("OVERWATCH_TOKEN=\n")
            ->and($output)->toContain("OVERWATCH_INSTANCE=\n")
            ->and($output)->toContain('schedule:run')
            ->and(file_get_contents(envPath()))->toBe("APP_NAME=Shop\nOVERWATCH_TOKEN=keep-me\n")
            ->and(file_get_contents(config_path('overwatch.php')))->toBe(file_get_contents(__DIR__.'/../../config/overwatch.php'));
    } finally {
        $before === null ? @unlink(envPath()) : file_put_contents(envPath(), $before);
    }
});

it('install keeps a config the app already published', function (): void {
    file_put_contents(config_path('overwatch.php'), "<?php\n\nreturn ['slow_query_ms' => 250];\n");

    [$code, $output] = runOverwatch('overwatch:install');

    expect($code)->toBe(0)
        ->and($output)->toContain('already')
        ->and(file_get_contents(config_path('overwatch.php')))->toBe("<?php\n\nreturn ['slow_query_ms' => 250];\n");
});

it('test --dry-run prints a schema 1 report and sends nothing', function (): void {
    Http::fake();
    oneClosedMinute();

    [$code, $output] = runOverwatch('overwatch:test', ['--dry-run' => true]);

    $report = printedReport($output);

    expect($code)->toBe(0)
        ->and($report['schema'])->toBe(1)
        ->and(array_keys($report))->toBe(array_keys(protocolExample()))
        ->and(minutesOf(instanceIn($report, 'web-1')))->toBe(['2026-11-18T13:05:00Z'])
        ->and($output)->toContain('Dry run');

    Http::assertNothingSent();
    nothingMarked();
});

it('test sends and reports the status without marking minutes sent', function (): void {
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    oneClosedMinute();

    [$code, $output] = runOverwatch('overwatch:test');

    expect($code)->toBe(0)
        ->and($output)->toContain('HTTP 202')
        ->and($output)->toContain('Overwatch took the report')
        ->and(printedReport($output))->toBe(receivedReport())
        ->and(minutesOf(instanceIn(receivedReport(), 'web-1')))->toBe(['2026-11-18T13:05:00Z'])
        ->and(receivedReport()['dropped_minutes'])->toBe(4);

    Http::assertSentCount(1);
    nothingMarked();

    // The real report still sends the minute the test sent, and marks it.
    expect(Artisan::call('overwatch:report'))->toBe(0)
        ->and(minutesOf(instanceIn(receivedReport(), 'web-1')))->toBe(['2026-11-18T13:05:00Z'])
        ->and(Progress::lastSent('web-1'))->toBe('202611181305');
});

it('says what each answer means and marks nothing', function (?int $status, array $body, string $meaning) {
    Http::fake(function () use ($status, $body) {
        if ($status === null) {
            throw new ConnectionException('cannot reach overwatch.test');
        }

        return Http::response($body, $status);
    });
    oneClosedMinute();

    [$code, $output] = runOverwatch('overwatch:test');

    expect($code)->toBe(1)
        ->and($output)->toContain($meaning)
        ->and($output)->not->toContain('cannot reach overwatch.test');

    nothingMarked();
})->with([
    '401' => [401, ['message' => 'Unauthenticated.'], 'OVERWATCH_TOKEN'],
    '422' => [422, ['errors' => ['schema' => ['The schema field is invalid.']]], 'The schema field is invalid.'],
    '413' => [413, [], 'refused the report (HTTP 413)'],
    '429' => [429, [], 'asked the app to slow down'],
    '503' => [503, [], 'asked the app to slow down'],
    '500' => [500, [], 'did not take the report (HTTP 500)'],
    'no connection' => [null, [], 'ConnectionException'],
]);

it('leaves the report lock to the real run', function (): void {
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    oneClosedMinute();

    // A real run holds the lock: the test still sends, and leaves it held.
    $held = Store::cache()->lock(Keys::reportLock(), 300);
    expect($held->get())->toBeTrue();

    expect(runOverwatch('overwatch:test')[0])->toBe(0)
        ->and(Store::cache()->lock(Keys::reportLock(), 300)->get())->toBeFalse()
        ->and($held->release())->toBeTrue();

    Http::assertSentCount(1);

    // With no run holding it, the test takes nothing a real run then waits for.
    expect(runOverwatch('overwatch:test')[0])->toBe(0)
        ->and(Store::cache()->lock(Keys::reportLock(), 300)->get())->toBeTrue();
});

it('says what is missing when not configured', function (string $command, array $settings, string $missing): void {
    $this->bootWith($settings);
    Http::fake();

    [$code, $output] = runOverwatch($command);

    expect($code)->toBe(1)
        ->and($output)->toContain($missing);

    Http::assertNothingSent();
})->with([
    'install, no token' => ['overwatch:install', ['token' => null], 'OVERWATCH_TOKEN'],
    'test, no token' => ['overwatch:test', ['token' => null], 'OVERWATCH_TOKEN'],
    'install, no url' => ['overwatch:install', ['url' => null], 'OVERWATCH_URL'],
    'test, no url' => ['overwatch:test', ['url' => null], 'OVERWATCH_URL'],
    'test, switched off' => ['overwatch:test', ['enabled' => false], 'OVERWATCH_ENABLED'],
]);

it('test --dry-run still shows the report when not configured', function (): void {
    $this->bootWith(['token' => null]);
    Http::fake();

    [$code, $output] = runOverwatch('overwatch:test', ['--dry-run' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('OVERWATCH_TOKEN')
        ->and(printedReport($output)['schema'])->toBe(1);

    Http::assertNothingSent();
});

it('never prints the token', function (string $command): void {
    $this->bootWith(['token' => 'satapp_secret_value']);
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    [, $output] = runOverwatch($command);

    expect($output)->not->toContain('satapp_secret_value');
})->with(['overwatch:install', 'overwatch:test']);
