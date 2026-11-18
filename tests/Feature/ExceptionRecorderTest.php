<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Recording\ErrorDetails;
use Arisultima\OverwatchAgent\Support\Fingerprint;

/**
 * The package root, used as the app's base path in this file so the fixture
 * that ships in tests/Fixtures is in-app and nothing is written at run time.
 */
function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

function throwerPath(): string
{
    return packageRoot().'/tests/Fixtures/Thrower.php';
}

/**
 * The line of a fixture statement.
 */
function throwerLine(string $needle): int
{
    foreach (file(throwerPath()) as $index => $line) {
        if (str_contains($line, $needle)) {
            return $index + 1;
        }
    }

    throw new LogicException('No line holds '.$needle);
}

beforeEach(function (): void {
    require_once throwerPath();

    $this->app->setBasePath(packageRoot());
    $this->freezeTime();
});

it('fingerprints the first in-app frame', function (): void {
    try {
        \OverwatchFixtures\throwHere();
    } catch (RuntimeException $e) {
    }

    $location = 'tests/Fixtures/Thrower.php:'.throwerLine('throw new');

    expect(Fingerprint::for($e, base_path()))->toBe([
        'fingerprint' => hash('sha256', 'RuntimeException|'.$location),
        'class' => 'RuntimeException',
        'location' => $location,
    ]);
});

it('walks the trace past vendor frames to the app', function (): void {
    try {
        \OverwatchFixtures\callVendor();
    } catch (Throwable $e) {
    }

    $location = 'tests/Fixtures/Thrower.php:'.throwerLine('app()->make');

    // Thrown inside the framework, under the app's vendor directory.
    expect($e->getFile())->toStartWith(base_path('vendor').DIRECTORY_SEPARATOR)
        ->and(Fingerprint::for($e, base_path()))->toBe([
            'fingerprint' => hash('sha256', $e::class.'|'.$location),
            'class' => $e::class,
            'location' => $location,
        ]);
});

it('skips frames under the app vendor directory', function (): void {
    $vendorFile = base_path('vendor/acme/lib/Thing.php');
    $appFile = base_path('app/Services/Billing.php');

    $e = new RuntimeException('x');
    $trace = new ReflectionProperty(Exception::class, 'trace');
    $trace->setValue($e, [['file' => $appFile, 'line' => 88]]);
    (new ReflectionProperty(Exception::class, 'file'))->setValue($e, $vendorFile);
    (new ReflectionProperty(Exception::class, 'line'))->setValue($e, 12);

    expect(Fingerprint::for($e, base_path())['location'])->toBe('app/Services/Billing.php:88');
});

it('says unknown when no frame is in the app', function (): void {
    // Thrown from this test file, which is outside that root.
    $e = new LogicException('nothing in app');

    expect(Fingerprint::for($e, base_path().'/nowhere'))->toBe([
        'fingerprint' => hash('sha256', 'LogicException|unknown'),
        'class' => 'LogicException',
        'location' => 'unknown',
    ]);
});

it('counts reported exceptions without the message', function (): void {
    report(new RuntimeException('user bob@example.com'));

    $counted = countedNow();

    expect($counted['errors.total'])->toBe(1)
        ->and(arrayStoreHolds('bob@example.com'))->toBeFalse();
});

it('counts a reported exception under its fingerprint with class and location', function (): void {
    try {
        \OverwatchFixtures\throwHere();
    } catch (RuntimeException $e) {
        report($e);
        report($e);
    }

    $fingerprint = Fingerprint::for($e, base_path());

    expect(countedNow())->toBe([
        'error.'.$fingerprint['fingerprint'] => 2,
        'errors.total' => 2,
    ])->and(app(ErrorDetails::class)->of([$fingerprint['fingerprint']]))->toBe([
        $fingerprint['fingerprint'] => [
            'class' => 'RuntimeException',
            'location' => 'tests/Fixtures/Thrower.php:'.throwerLine('throw new'),
        ],
    ])->and(arrayStoreHolds('bob@example.com'))->toBeFalse();
});

it('lets reporting continue to the log', function (): void {
    $logged = [];
    \Illuminate\Support\Facades\Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message;
    });

    report(new RuntimeException('still logged'));

    expect($logged)->toHaveCount(1);
});

it('does not count exceptions the app does not report', function (): void {
    report(new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('gone'));

    expect(countedNow())->toBe([]);
});

it('counts nothing when disabled', function (): void {
    $this->bootWith(['token' => null]);
    $this->freezeTime();

    report(new RuntimeException('user bob@example.com'));

    expect(countedNow())->toBe([]);
});

it('names an anonymous exception class without its file path', function (): void {
    report(new class('user bob@example.com') extends RuntimeException {});

    $location = 'tests/Feature/ExceptionRecorderTest.php:'.(__LINE__ - 2);
    $fingerprint = hash('sha256', 'RuntimeException@anonymous|'.$location);

    expect(countedNow())->toBe(['error.'.$fingerprint => 1, 'errors.total' => 1])
        ->and(app(ErrorDetails::class)->of([$fingerprint]))->toBe([
            $fingerprint => ['class' => 'RuntimeException@anonymous', 'location' => $location],
        ])
        ->and(arrayStoreHolds(__FILE__))->toBeFalse()
        ->and(arrayStoreHolds('bob@example.com'))->toBeFalse();
});
