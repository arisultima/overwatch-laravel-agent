<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Report\Sender;
use Arisultima\OverwatchAgent\Report\SendResult;
use Arisultima\OverwatchAgent\Report\Trimmer;
use Arisultima\OverwatchAgent\Support\Package;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts gzip JSON with the bearer token', function () {
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    $report = app(ReportBuilder::class)->build(at('13:06:30'))['report'];

    $result = app(Sender::class)->send($report);

    expect($result->isAccepted())->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($report): bool {
        $json = gzdecode($request->body());
        $decoded = json_decode((string) $json, true);

        return $request->method() === 'POST'
            && $request->url() === 'https://overwatch.test/api/agent/v1/app-report'
            && $request->header('Authorization') === ['Bearer satapp_test']
            && $request->header('Content-Encoding') === ['gzip']
            && $request->header('Content-Type') === ['application/json']
            && $request->header('User-Agent') === ['overwatch-laravel-agent/'.Package::version()]
            && $json === Trimmer::encode($report)
            && is_array($decoded)
            && $decoded['schema'] === 1;
    });
});

it('never puts the token in the URL', function () {
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    app(Sender::class)->send(['schema' => 1]);

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'satapp_'));
});

it('reads the status into accepted, rejected or keep', function (int $status, string $kind) {
    Http::fake(['*' => Http::response(['message' => 'x'], $status)]);

    $result = app(Sender::class)->send(['schema' => 1]);

    expect($result->kind)->toBe($kind)
        ->and($result->status)->toBe($status);
})->with([
    '202' => [202, SendResult::ACCEPTED],
    '422' => [422, SendResult::REJECTED],
    '413' => [413, SendResult::REJECTED],
    '200' => [200, SendResult::KEEP],
    '204' => [204, SendResult::KEEP],
    '301' => [301, SendResult::KEEP],
    '401' => [401, SendResult::KEEP],
    '404' => [404, SendResult::KEEP],
    '429' => [429, SendResult::KEEP],
    '500' => [500, SendResult::KEEP],
    '503' => [503, SendResult::KEEP],
]);

it('takes the first validation message from a 422', function () {
    Http::fake(['*' => Http::response([
        'message' => 'The instances field is required. (and 1 more error)',
        'errors' => ['instances' => ['The instances field is required.'], 'window' => ['Bad window.']],
    ], 422)]);

    expect(app(Sender::class)->send(['schema' => 1])->error)->toBe('The instances field is required.');
});

it('falls back to the message, or to nothing, on a refusal without errors', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['message' => 'Unknown schema.'], 422)
        ->push('<html>413 Request Entity Too Large</html>', 413)]);

    $sender = app(Sender::class);

    expect($sender->send(['schema' => 1])->error)->toBe('Unknown schema.')
        ->and($sender->send(['schema' => 1])->error)->toBeNull();
});

it('keeps a refusal message to one short line', function () {
    Http::fake(['*' => Http::response(['errors' => ['x' => ["Line one\nline two ".str_repeat('a', 500)]]], 422)]);

    $error = app(Sender::class)->send(['schema' => 1])->error;

    expect($error)->toStartWith('Line one line two ')
        ->and(strlen((string) $error))->toBeLessThanOrEqual(200)
        ->and($error)->not->toContain("\n");
});

it('gives up within the timeouts, with no retry', function () {
    $options = app(Sender::class)->request()->getOptions();

    expect($options['timeout'])->toBe(5)
        ->and($options['connect_timeout'])->toBe(3)
        ->and($options['allow_redirects'])->toBeFalse();

    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('down');
    });

    $result = app(Sender::class)->send(['schema' => 1]);

    expect($result->isKeep())->toBeTrue()
        ->and($result->status)->toBeNull()
        ->and($result->error)->toBe('ConnectionException')
        ->and($calls)->toBe(1);
});

it('sends nothing without a URL', function () {
    config(['overwatch.url' => null]);
    Http::fake();

    expect(app(Sender::class)->send(['schema' => 1])->isKeep())->toBeTrue();

    Http::assertNothingSent();
});

it('returns within 6 seconds when the server hangs', function () {
    // A socket that takes the connection and never answers.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($server === false) {
        $this->markTestSkipped('Cannot listen on 127.0.0.1: '.$error);
    }

    config(['overwatch.url' => 'http://'.stream_socket_get_name($server, false)]);

    $started = microtime(true);
    $result = app(Sender::class)->send(['schema' => 1]);
    $elapsed = microtime(true) - $started;

    fclose($server);

    expect($result->isKeep())->toBeTrue()
        ->and($result->status)->toBeNull()
        ->and($elapsed)->toBeGreaterThanOrEqual(4.5)
        ->and($elapsed)->toBeLessThan(8.0); // 5 s timeout plus slack for a loaded CI runner
});
