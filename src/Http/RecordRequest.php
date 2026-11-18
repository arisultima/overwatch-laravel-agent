<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware that counts each request once the response has been sent.
 * It never changes the request or the response.
 */
final class RecordRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        //
    }
}
