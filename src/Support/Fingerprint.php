<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Support;

use Throwable;

/**
 * What makes two exceptions the same problem: the class and the first frame
 * in the app's own code. The same value sat/diagnostics computes, so an error
 * group in a report can be matched to its captured events there; where the
 * two differ (anonymous classes, entry scripts) it says so below.
 *
 * Only the class, the file paths and the line numbers of the trace are read;
 * never the message or the frame arguments.
 */
final class Fingerprint
{
    public const UNKNOWN = 'unknown';

    private const ANONYMOUS = '@anonymous';

    /**
     * The app's entry scripts, relative to its root. Every console process
     * starts at artisan and every request at public/index.php (server.php on
     * older apps), so as a location they name no problem: a failing queued
     * closure, whose code runs from a stream, would otherwise group at
     * artisan:16 with every other failing closure.
     */
    private const ENTRY_SCRIPTS = ['artisan', 'public/index.php', 'server.php'];

    /**
     * @return array{fingerprint: string, class: string, location: string}
     */
    public static function for(Throwable $e, string $basePath): array
    {
        $class = self::className($e);
        $location = self::location($e, $basePath);

        return [
            'fingerprint' => hash('sha256', $class.'|'.$location),
            'class' => $class,
            'location' => $location,
        ];
    }

    /**
     * The class name, with an anonymous class cut right after "@anonymous".
     * PHP names one "RuntimeException@anonymous", a NUL byte, then the
     * absolute path and line where it is declared, which would put a server
     * path in the report and change the fingerprint with every release
     * directory. The cut name is both stored and hashed, so for anonymous
     * classes only this fingerprint knowingly differs from sat/diagnostics,
     * which hashes the raw name.
     */
    public static function className(Throwable $e): string
    {
        $class = $e::class;
        $at = strpos($class, self::ANONYMOUS);

        return $at === false ? $class : substr($class, 0, $at + strlen(self::ANONYMOUS));
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
     * code: under the root, not under a vendor/ directory there and not one
     * of the entry scripts. Judged on the relative path, so an app deployed
     * below a folder called vendor still counts.
     *
     * sat/diagnostics counts the entry scripts as in-app, so where one of
     * them is the only in-app frame the two fingerprints differ: here the
     * location falls to "unknown" (or a deeper in-app frame), there it is the
     * entry script.
     */
    private static function inApp(string $file, string $base): ?string
    {
        $file = str_replace('\\', '/', $file);

        if ($file === '' || ! str_starts_with($file, $base)) {
            return null;
        }

        $relative = substr($file, strlen($base));

        if ($relative === '' || str_starts_with($relative, 'vendor/') || str_contains($relative, '/vendor/')
            || in_array($relative, self::ENTRY_SCRIPTS, true)) {
            return null;
        }

        return $relative;
    }
}
