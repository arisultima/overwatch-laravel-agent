<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Recording;

/**
 * Per-instance, per-minute sets of keyed hashes, sized for the report's
 * `distinct` block. An identifier (an address, an account) is never stored:
 * only hash_hmac('sha256', identifier, app key), with a count per hash.
 */
interface DistinctSets
{
    /**
     * The most members a set holds. A new member past it is not stored and
     * the set is marked capped; members already in it keep counting.
     */
    public const CAP = 1000;

    public function add(string $instance, string $minute, string $set, string $identifier): void;

    /**
     * @return array{distinct: int, max: int, capped: bool}
     */
    public function sizes(string $instance, string $minute, string $set): array;
}
