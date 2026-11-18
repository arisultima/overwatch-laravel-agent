<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Report;

use Arisultima\OverwatchAgent\Config;
use Arisultima\OverwatchAgent\Support\Package;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Posts one report to Overwatch: the gzip of exactly the JSON Trimmer
 * measured, with the token in the Authorization header. One attempt, no
 * retry and no redirect, given up after 3 s to connect and 5 s in all, so a
 * hung server costs the scheduler at most a few seconds a minute.
 *
 * Only the status is read from a response, and on a refusal the first
 * validation message, for the log. Nothing a response says is acted on.
 */
final class Sender
{
    public const PATH = '/api/agent/v1/app-report';

    public const CONNECT_TIMEOUT_SECONDS = 3;

    public const TIMEOUT_SECONDS = 5;

    /**
     * The longest refusal message kept for the log.
     */
    private const MAX_ERROR_LENGTH = 200;

    public function __construct(private readonly Factory $http) {}

    /**
     * Never throws: whatever goes wrong before an answer is a Keep.
     *
     * @param  array<string, mixed>  $report
     */
    public function send(array $report): SendResult
    {
        $url = Config::url();

        if ($url === '') {
            return SendResult::keep();
        }

        try {
            $body = gzencode(Trimmer::encode($report));

            if ($body === false) {
                return SendResult::keep();
            }

            $response = $this->request()
                ->withBody($body, 'application/json')
                ->post($url.self::PATH);
        } catch (Throwable) {
            return SendResult::keep();
        }

        return self::result($response);
    }

    /**
     * The request without its body. Public so the timeouts can be checked.
     */
    public function request(): PendingRequest
    {
        return $this->http
            ->withToken(Config::token())
            ->withHeaders([
                'Content-Encoding' => 'gzip',
                // The server's errors come back as JSON, not a redirect.
                'Accept' => 'application/json',
            ])
            ->withUserAgent('overwatch-laravel-agent/'.Package::version())
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->withoutRedirecting();
    }

    private static function result(Response $response): SendResult
    {
        $status = $response->status();

        return match (true) {
            $status === 202 => SendResult::accepted($status),
            $status === 422, $status === 413 => SendResult::rejected($status, self::firstError($response)),
            default => SendResult::keep($status),
        };
    }

    /**
     * The first message under `errors`, else `message`, as one short line;
     * null when the body has neither (a proxy's HTML page).
     */
    private static function firstError(Response $response): ?string
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($body)) {
            return null;
        }

        $message = null;

        if (is_array($body['errors'] ?? null)) {
            foreach ($body['errors'] as $messages) {
                $first = is_array($messages) ? reset($messages) : $messages;

                if (is_string($first) && trim($first) !== '') {
                    $message = $first;

                    break;
                }
            }
        }

        $message ??= is_string($body['message'] ?? null) ? $body['message'] : null;

        if ($message === null) {
            return null;
        }

        $line = trim((string) preg_replace('/[\s\p{Cc}]+/u', ' ', $message));

        if ($line === '') {
            return null;
        }

        return mb_strlen($line) > self::MAX_ERROR_LENGTH ? mb_substr($line, 0, self::MAX_ERROR_LENGTH - 3).'...' : $line;
    }
}
