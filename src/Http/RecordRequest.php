<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Http;

use Arisultima\OverwatchAgent\Config;
use Arisultima\OverwatchAgent\Recording\Recorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global middleware that counts each request once the response has been sent.
 * It never changes the request or the response, and reads nothing of them
 * but the status, the route name and how long the request took.
 *
 * Bound as a singleton, so terminate() runs on the instance whose handle()
 * noted the start.
 */
final class RecordRequest
{
    /**
     * Statuses counted on their own as well as in their class.
     */
    private const WATCHED = [401, 403, 419, 429];

    private ?float $startedAt = null;

    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->startedAt = microtime(true);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->record($request, $response->getStatusCode());
        } catch (Throwable) {
            // Recording never throws into the app.
        } finally {
            $this->startedAt = null;
        }
    }

    private function record(Request $request, int $status): void
    {
        $this->recorder->count('http.requests');

        if ($status >= 200 && $status <= 599) {
            $this->recorder->count('http.'.intdiv($status, 100).'xx');
        }

        if (in_array($status, self::WATCHED, true)) {
            $this->recorder->count('http.'.$status);
        }

        if ($status === 429 && in_array(self::routeName($request), Config::signinRoutes(), true)) {
            $this->recorder->count('auth.login_throttled');
        }

        $ms = $this->duration();

        if ($ms === null) {
            return;
        }

        $this->recorder->histogram($ms);

        if ($ms > Config::slowRequestMs()) {
            $this->recorder->count('http.slow');
        }
    }

    /**
     * Whole milliseconds since the request started, rounded up so the band
     * edges and the slow threshold compare exactly: 50.2 ms is over 50.
     * From LARAVEL_START when the front controller defined it, else from
     * this middleware's own start; null when neither is known.
     */
    private function duration(): ?int
    {
        $start = defined('LARAVEL_START') && is_float(LARAVEL_START) ? LARAVEL_START : $this->startedAt;

        if ($start === null) {
            return null;
        }

        return (int) ceil(max(0.0, (microtime(true) - $start) * 1000));
    }

    private static function routeName(Request $request): ?string
    {
        $route = $request->route();

        return $route instanceof Route ? $route->getName() : null;
    }
}
