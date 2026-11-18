<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Minutes are named YmdHi in UTC, so every machine and the reporter agree on
 * which bucket a moment belongs to whatever the app timezone.
 */
final class Minute
{
    public const FORMAT = 'YmdHi';

    /**
     * The most minutes a range returns; a report never covers more.
     */
    public const MAX_RANGE = 60;

    public static function current(?CarbonInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? Carbon::now())->utc()->format(self::FORMAT);
    }

    /**
     * The minutes strictly between two minutes, oldest first. When more than
     * 60 lie between them, only the newest 60 are listed.
     *
     * @return list<string>
     */
    public static function range(string $fromExclusive, string $toExclusive): array
    {
        $from = self::parse($fromExclusive);
        $to = self::parse($toExclusive);

        if ($from === null || $to === null || $to <= $from) {
            return [];
        }

        $start = max($from->addMinute(), $to->subMinutes(self::MAX_RANGE));
        $minutes = [];

        for ($at = $start; $at < $to; $at = $at->addMinute()) {
            $minutes[] = $at->format(self::FORMAT);
        }

        return $minutes;
    }

    public static function parse(string $minute): ?CarbonImmutable
    {
        if (preg_match('/^\d{12}$/', $minute) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!'.self::FORMAT, $minute, 'UTC');

        return $parsed instanceof CarbonImmutable && $parsed->format(self::FORMAT) === $minute ? $parsed : null;
    }
}
