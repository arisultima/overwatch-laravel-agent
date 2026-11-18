<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Arisultima\OverwatchAgent\Support\Fingerprint;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Counts reported exceptions by fingerprint. The class and the first in-app
 * frame are kept; never the message, the trace or anything of the request.
 */
final class ExceptionRecorder
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly string $basePath,
    ) {}

    /**
     * Hook the handler's reportable callbacks. The callback returns nothing,
     * so reporting carries on to the log and any other callback as before.
     * Exceptions the app does not report (dontReport, HTTP and validation
     * exceptions) never reach it, so they are not counted either.
     *
     * @param  Closure(): self  $recorder  resolves the recorder on first use
     */
    public static function attach(ExceptionHandler $handler, Closure $recorder): void
    {
        // reportable() is on the framework's Handler, not on the contract.
        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $e) use ($recorder): void {
            try {
                $recorder()->record($e);
            } catch (Throwable) {
                // Recording never throws into the app.
            }
        });
    }

    public function record(Throwable $e): void
    {
        try {
            $fingerprint = Fingerprint::for($e, $this->basePath);
        } catch (Throwable) {
            return;
        }

        $this->recorder->count('errors.total');
        $this->recorder->error($fingerprint['fingerprint'], $fingerprint['class'], $fingerprint['location']);
    }
}
