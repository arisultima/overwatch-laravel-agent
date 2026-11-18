<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Probes;

use Arisultima\OverwatchAgent\Support\Store;
use Composer\InstalledVersions;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The installed Composer packages, sent at most every 6 hours, or at once
 * when composer.lock changes (a deploy).
 */
final class Versions
{
    public const KEY = 'overwatch:versions';

    /**
     * The last composer.lock hash, with the path, time and size it was
     * taken from, so the file is read only when one of them changed.
     */
    public const LOCK_KEY = 'overwatch:lock-hash';

    public const EVERY_SECONDS = 6 * 3600;

    public const MAX_PACKAGES = 1500;

    /**
     * Nothing sent in 6 hours, or the lock hash differs from the one last
     * sent. False when the store cannot be read: a reporter that cannot
     * remember sending would otherwise send the whole list every minute.
     */
    public static function due(): bool
    {
        try {
            $last = Store::cache()->get(self::KEY);
        } catch (Throwable) {
            return false;
        }

        if (! is_array($last) || ! is_string($last['hash'] ?? null) || ! is_int($last['at'] ?? null)) {
            return true;
        }

        if (Carbon::now()->getTimestamp() - $last['at'] >= self::EVERY_SECONDS) {
            return true;
        }

        return $last['hash'] !== self::lockHash();
    }

    /**
     * The report's `versions` object.
     *
     * @return array{lock_hash: string, packages: list<array{0: string, 1: string, 2: bool}>, packages_truncated: int}
     */
    public static function section(): array
    {
        return self::fromRawData(self::rawData(), self::lockHash());
    }

    /**
     * Remember a sent list, so the next one waits 6 hours or a new lock.
     * Never throws; a store that fails cannot be read for due() either.
     */
    public static function markSent(string $lockHash): void
    {
        try {
            Store::cache()->forever(self::KEY, ['hash' => $lockHash, 'at' => Carbon::now()->getTimestamp()]);
        } catch (Throwable) {
            // due() answers false while the store is down.
        }
    }

    /**
     * The sha256 of the app's composer.lock. The file is read only when its
     * path, modification time or size differ from the last hash taken; a
     * rewrite within the same second at the same size goes unseen until the
     * 6 hour resend. An app deployed without a lock file is identified by
     * the installed list itself, so a change still shows.
     */
    public static function lockHash(): string
    {
        $path = app()->basePath('composer.lock');

        clearstatcache(true, $path);

        $mtime = @filemtime($path);
        $size = @filesize($path);

        if ($mtime === false || $size === false) {
            return self::installedHash();
        }

        $file = ['path' => $path, 'mtime' => $mtime, 'size' => $size];

        try {
            $known = Store::cache()->get(self::LOCK_KEY);
        } catch (Throwable) {
            $known = null;
        }

        if (is_array($known) && is_string($known['hash'] ?? null)
            && ($known['path'] ?? null) === $path && ($known['mtime'] ?? null) === $mtime && ($known['size'] ?? null) === $size) {
            return $known['hash'];
        }

        $hash = @hash_file('sha256', $path);

        if (! is_string($hash)) {
            return self::installedHash();
        }

        try {
            Store::cache()->forever(self::LOCK_KEY, [...$file, 'hash' => $hash]);
        } catch (Throwable) {
            // Read again next time.
        }

        return $hash;
    }

    /**
     * Name, pretty version and dev flag of every installed package, sorted by
     * name, at most 1500. The app's own root package and packages that are
     * only replaced or provided (no version installed) are left out.
     *
     * @internal
     *
     * @param  array<int, mixed>  $raw  Composer\InstalledVersions::getAllRawData()
     * @return array{lock_hash: string, packages: list<array{0: string, 1: string, 2: bool}>, packages_truncated: int}
     */
    public static function fromRawData(array $raw, string $lockHash): array
    {
        $packages = [];

        foreach ($raw as $set) {
            if (! is_array($set) || ! is_array($set['versions'] ?? null)) {
                continue;
            }

            $root = $set['root']['name'] ?? null;

            foreach ($set['versions'] as $name => $package) {
                if (! is_string($name) || $name === $root || isset($packages[$name])
                    || ! is_array($package) || ! is_scalar($package['pretty_version'] ?? null)) {
                    continue;
                }

                $packages[$name] = [$name, (string) $package['pretty_version'], (bool) ($package['dev_requirement'] ?? false)];
            }
        }

        ksort($packages, SORT_STRING);

        $packages = array_values($packages);

        return [
            'lock_hash' => $lockHash,
            'packages' => array_slice($packages, 0, self::MAX_PACKAGES),
            'packages_truncated' => max(0, count($packages) - self::MAX_PACKAGES),
        ];
    }

    /**
     * The hash of the installed list, for an app without a readable lock.
     */
    private static function installedHash(): string
    {
        return hash('sha256', (string) json_encode(self::fromRawData(self::rawData(), '')['packages']));
    }

    /**
     * @return array<int, mixed>
     */
    private static function rawData(): array
    {
        return class_exists(InstalledVersions::class) ? InstalledVersions::getAllRawData() : [];
    }
}
