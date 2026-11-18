<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

/**
 * Cache key names, in one place so the drivers and the reporter agree.
 *
 * @internal
 */
final class Keys
{
    /**
     * The counters of one instance in one minute: a Redis hash, or the stem
     * of the cache driver's per-counter keys.
     */
    public static function bucket(string $instance, string $minute): string
    {
        return 'overwatch:b:'.self::segment($instance).':'.self::segment($minute);
    }

    /**
     * One distinct set of one instance in one minute. The braces are a Redis
     * hash tag, so the set and its markers share a cluster slot.
     */
    public static function set(string $instance, string $minute, string $set): string
    {
        return 'overwatch:d:{'.self::segment($instance).':'.self::segment($minute).':'.self::segment($set).'}';
    }

    /**
     * The class and location behind an error fingerprint.
     */
    public static function error(string $fingerprint): string
    {
        return 'overwatch:e:'.self::segment($fingerprint);
    }

    /**
     * A name as a key segment. Plain names stay readable; anything with
     * spaces, colons, braces or other characters some stores refuse (or over
     * 100 characters) becomes a hash of itself.
     */
    public static function segment(string $value): string
    {
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value) === 1 ? $value : '~'.sha1($value);
    }
}
