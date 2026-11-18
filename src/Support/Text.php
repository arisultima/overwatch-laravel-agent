<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Support;

/**
 * A text field as the server takes it: valid UTF-8, no surrounding
 * whitespace, never empty and at most so many characters. The server
 * refuses a whole report over one field that is not.
 */
final class Text
{
    public const UNKNOWN = 'unknown';

    /**
     * Laravel's own trim (Str::trim, TrimStrings): whitespace plus the byte
     * order mark, zero-width space and left-to-right mark at either end.
     */
    private const TRIM = '~^[\s\x{FEFF}\x{200B}\x{200E}]+|[\s\x{FEFF}\x{200B}\x{200E}]+$~u';

    /**
     * The value cut to at most $max characters (never inside a multibyte
     * character), or "unknown" when it is empty or not a string or number.
     */
    public static function fit(mixed $value, int $max): string
    {
        $value = self::clean($value);

        if ($value !== '') {
            $value = self::trim(mb_substr($value, 0, $max, 'UTF-8'));
        }

        return $value === '' ? self::UNKNOWN : $value;
    }

    /**
     * Whether the value is already a field the server takes, as it is.
     */
    public static function fits(mixed $value, int $max): bool
    {
        return is_string($value) && $value === self::clean($value) && $value !== '' && mb_strlen($value, 'UTF-8') <= $max;
    }

    /**
     * The value as valid UTF-8 without surrounding whitespace; empty when it
     * is not a string or number.
     */
    public static function clean(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        return self::trim(mb_scrub((string) $value, 'UTF-8'));
    }

    private static function trim(string $value): string
    {
        return preg_replace(self::TRIM, '', $value) ?? trim($value);
    }
}
