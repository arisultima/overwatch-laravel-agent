<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Support;

use Throwable;

/**
 * What makes two exceptions the same problem: the class and the first frame
 * in the app's own code. The same value sat/diagnostics computes, so an error
 * group in a report can be matched to its captured events there.
 *
 * Only the class, the file paths and the line numbers of the trace are read;
 * never the message or the frame arguments.
 */
final class Fingerprint
{
    public const UNKNOWN = 'unknown';

    /**
     * @return array{fingerprint: string, class: string, location: string}
     */
    public static function for(Throwable $e, string $basePath): array
    {
        $class = $e::class;
        $location = self::location($e, $basePath);

        return [
            'fingerprint' => hash('sha256', $class.'|'.$location),
            'class' => $class,
            'location' => $location,
        ];
    }

    /**
     * The throw site when it is in the app, else the first in-app frame of
     * the trace, as "relative/path.php:line"; "unknown" when there is none.
     */
    private static function location(Throwable $e, string $basePath): string
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        $relative = self::inApp($e->getFile(), $base);

        if ($relative !== null) {
            return $relative.':'.$e->getLine();
        }

        foreach ($e->getTrace() as $frame) {
            if (! isset($frame['file']) || ! is_string($frame['file'])) {
                continue;
            }

            $relative = self::inApp($frame['file'], $base);

            if ($relative !== null) {
                return $relative.':'.(int) ($frame['line'] ?? 0);
            }
        }

        return self::UNKNOWN;
    }

    /**
     * The path relative to the app root when the file is in the app's own
     * code: under the root and not under a vendor/ directory there. Judged on
     * the relative path, so an app deployed below a folder called vendor
     * still counts.
     */
    private static function inApp(string $file, string $base): ?string
    {
        $file = str_replace('\\', '/', $file);

        if ($file === '' || ! str_starts_with($file, $base)) {
            return null;
        }

        $relative = substr($file, strlen($base));

        if ($relative === '' || str_starts_with($relative, 'vendor/') || str_contains($relative, '/vendor/')) {
            return null;
        }

        return $relative;
    }
}
