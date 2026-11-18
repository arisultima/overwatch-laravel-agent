<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Probes\Versions;
use Illuminate\Support\Str;

/**
 * A base path holding a composer.lock with this content. The directory is
 * removed after the test; only directories this test made are touched.
 */
function lockFixture(string $content): string
{
    $dir = tempLockDir();
    file_put_contents($dir.'/composer.lock', $content);

    return $dir;
}

function tempLockDir(): string
{
    $dir = sys_get_temp_dir().'/overwatch-versions-'.Str::random(10);
    mkdir($dir);

    test()->beforeApplicationDestroyed(function () use ($dir) {
        @unlink($dir.'/composer.lock');
        @rmdir($dir);
    });

    return $dir;
}

it('sends versions only when due', function () {
    $this->freezeTime();
    $this->app->setBasePath(lockFixture('{"packages": []}'));

    expect(Versions::due())->toBeTrue();

    $section = Versions::section();
    expect($section['lock_hash'])->toBe(hash('sha256', '{"packages": []}'));

    Versions::markSent($section['lock_hash']);
    expect(Versions::due())->toBeFalse();

    file_put_contents(base_path('composer.lock'), '{"packages": [{"name": "a/b"}]}');
    expect(Versions::due())->toBeTrue();
});

it('identifies an app without a composer.lock by its installed list', function () {
    $this->app->setBasePath(tempLockDir());

    $hash = Versions::section()['lock_hash'];

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->toBe(Versions::lockHash());

    Versions::markSent($hash);

    expect(Versions::due())->toBeFalse();
});

it('sends versions again after six hours', function () {
    $this->freezeTime();
    $this->app->setBasePath(lockFixture('{}'));

    Versions::markSent(Versions::section()['lock_hash']);

    $this->travel(6 * 3600 - 1)->seconds();
    expect(Versions::due())->toBeFalse();

    $this->travel(1)->seconds();
    expect(Versions::due())->toBeTrue();
});

it('lists the installed packages sorted, with their dev flag', function () {
    $section = Versions::section();

    expect(array_keys($section))->toBe(['lock_hash', 'packages', 'packages_truncated'])
        ->and($section['lock_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($section['packages_truncated'])->toBe(0);

    $names = array_column($section['packages'], 0);
    $sorted = $names;
    sort($sorted, SORT_STRING);

    expect($names)->toBe($sorted)
        ->and($names)->toBe(array_values(array_unique($names)))
        ->and($names)->toContain('laravel/framework')
        ->and($names)->not->toContain('arisultima/overwatch-laravel-agent');

    $byName = array_column($section['packages'], null, 0);

    expect($byName['laravel/framework'][1])->toBe(\Composer\InstalledVersions::getPrettyVersion('laravel/framework'))
        ->and($byName['laravel/framework'][2])->toBeFalse()
        ->and($byName['pestphp/pest'][2])->toBeTrue();

    foreach ($section['packages'] as $package) {
        expect($package)->toHaveCount(3)
            ->and($package[0])->toBeString()
            ->and($package[1])->toBeString()
            ->and($package[2])->toBeBool();
    }
});

it('caps the package list at 1500', function () {
    $packages = [];

    foreach (range(1, 1600) as $i) {
        $packages[sprintf('vendor/p%04d', $i)] = ['pretty_version' => '1.0.0', 'dev_requirement' => false];
    }

    $section = Versions::fromRawData([['root' => ['name' => 'app/root'], 'versions' => $packages]], 'hash');

    expect($section['packages'])->toHaveCount(1500)
        ->and($section['packages_truncated'])->toBe(100)
        ->and($section['packages'][0])->toBe(['vendor/p0001', '1.0.0', false])
        ->and($section['packages'][1499][0])->toBe('vendor/p1500');
});

it('reads composer.lock only when its time or size changed', function () {
    $this->app->setBasePath(lockFixture('{"packages": []}'));

    $real = Versions::lockHash();
    expect($real)->toBe(hash('sha256', '{"packages": []}'));

    // A remembered hash for the same file, time and size is used as is.
    $entry = cache()->store()->get(Versions::LOCK_KEY);
    cache()->store()->forever(Versions::LOCK_KEY, [...$entry, 'hash' => str_repeat('a', 64)]);

    expect(Versions::lockHash())->toBe(str_repeat('a', 64));

    file_put_contents(base_path('composer.lock'), '{"packages": [{"name": "a/b"}]}');

    expect(Versions::lockHash())->toBe(hash('sha256', '{"packages": [{"name": "a/b"}]}'));
});
