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
     * The last local probe stamp of one instance.
     */
    public static function stamp(string $instance): string
    {
        return 'overwatch:stamp:'.self::segment($instance);
    }

    /**
     * The names of the instances that have stamped, so the reporter can find
     * them; a cache cannot list its keys.
     */
    public static function instances(): string
    {
        return 'overwatch:instances';
    }

    /**
     * The last minute of one instance a report covered and the server
     * took (or refused for good). Kept without expiry.
     */
    public static function progress(string $instance): string
    {
        return 'overwatch:sent:'.self::segment($instance);
    }

    /**
     * Minutes lost since the last accepted report, beyond those the next
     * report works out itself. Kept without expiry.
     */
    public static function dropped(): string
    {
        return 'overwatch:dropped';
    }

    /**
     * The lock one report run holds while it builds, sends and marks, so two
     * nodes sharing the overwatch store never send the same minutes.
     */
    public static function reportLock(): string
    {
        return 'overwatch:report';
    }

    /**
     * Set for an hour after a refused token is logged, so the log says it
     * once an hour, not once a minute.
     */
    public static function unauthorizedLogged(): string
    {
        return 'overwatch:unauthorized-logged';
    }

    /**
     * Set for an hour after a failure of this exception class is logged.
     */
    public static function failureLogged(string $class): string
    {
        return 'overwatch:failure-logged:'.self::segment($class);
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
