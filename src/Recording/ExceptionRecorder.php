<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

use Arisultima\OverwatchAgent\Support\Fingerprint;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use ReflectionObject;
use Throwable;
use WeakMap;

/**
 * Counts reported exceptions by fingerprint. The class and the first in-app
 * frame are kept; never the message, the trace or anything of the request.
 */
final class ExceptionRecorder
{
    /**
     * The handlers that hold the callback, held weakly so a handler the app
     * drops (or a test's whole app) is not kept alive.
     *
     * @var WeakMap<ExceptionHandler, true>|null
     */
    private static ?WeakMap $attached = null;

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
     * Attached once to the handler that holds the callbacks, however often
     * the binding is resolved or decorated. The provider's hook runs again
     * whenever the binding is rebuilt, and a decorator such as collision's
     * console handler wraps the app's handler and forwards report() (and
     * reportable()) to it, so attaching per resolved handler counted every
     * exception twice. The callback goes on the wrapped handler itself, so a
     * decorator without reportable() is covered too. A handler that replaces
     * the app's own, rather than wrapping it, gets its own callback.
     *
     * @param  Closure(): self  $recorder  resolves the recorder on first use
     */
    public static function attach(ExceptionHandler $handler, Closure $recorder): void
    {
        $target = self::innermost($handler);

        // reportable() is on the framework's Handler, not on the contract.
        if (! method_exists($target, 'reportable')) {
            return;
        }

        self::$attached ??= new WeakMap;

        if (isset(self::$attached[$target])) {
            return;
        }

        self::$attached[$target] = true;

        $target->reportable(function (Throwable $e) use ($recorder): void {
            try {
                $recorder()->record($e);
            } catch (Throwable) {
                // Recording never throws into the app.
            }
        });
    }

    /**
     * The handler a decorator wraps, followed down to the one that is not a
     * decorator: the first property of each that holds another handler, as
     * collision's handler and the framework's exceptions fake do. Unwrapped
     * only while the handler is not the framework's own, which keeps the
     * callbacks itself.
     */
    private static function innermost(ExceptionHandler $handler): ExceptionHandler
    {
        $seen = [spl_object_id($handler) => true];

        while (! $handler instanceof \Illuminate\Foundation\Exceptions\Handler) {
            $inner = self::wrapped($handler);

            if ($inner === null || isset($seen[spl_object_id($inner)])) {
                break;
            }

            $seen[spl_object_id($inner)] = true;
            $handler = $inner;
        }

        return $handler;
    }

    private static function wrapped(ExceptionHandler $handler): ?ExceptionHandler
    {
        try {
            foreach ((new ReflectionObject($handler))->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($handler)) {
                    continue;
                }

                $value = $property->getValue($handler);

                if ($value instanceof ExceptionHandler && $value !== $handler) {
                    return $value;
                }
            }
        } catch (Throwable) {
            // An odd handler is taken as it is.
        }

        return null;
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
