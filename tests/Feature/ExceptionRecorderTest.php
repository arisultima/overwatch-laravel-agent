<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Recording\ErrorDetails;
use Arisultima\OverwatchAgent\Support\Fingerprint;
use Arisultima\OverwatchAgent\Tests\Fixtures\ForwardingHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;

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

it('counts an exception once in a console process with collision installed', function (): void {
    // Collision resolves the app's handler while it registers and binds its
    // own over it, which forwards reportable() to the app's handler.
    $this->earlierProviders = [\NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider::class];
    $this->environment = 'local';
    $this->bootWith([]);
    $this->freezeTime();

    expect(app()->runningInConsole())->toBeTrue()
        ->and(app(ExceptionHandler::class))->toBeInstanceOf(\NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler::class);

    report(new RuntimeException('once'));

    expect(countedNow()['errors.total'])->toBe(1);
});

it('counts an exception once when a decorated handler is resolved again', function (): void {
    $inner = app(ExceptionHandler::class);

    app()->singleton(ExceptionHandler::class, fn (): ExceptionHandler => new ForwardingHandler($inner));
    app(ExceptionHandler::class);

    // Resolved again: a second decorator over the same handler.
    app()->forgetInstance(ExceptionHandler::class);
    app(ExceptionHandler::class);

    // And decorated twice over.
    app()->singleton(ExceptionHandler::class, fn (): ExceptionHandler => new ForwardingHandler(new ForwardingHandler($inner)));
    app(ExceptionHandler::class);

    report(new RuntimeException('once'));

    expect(countedNow()['errors.total'])->toBe(1);
});

it('counts through a decorator that has no reportable()', function (): void {
    // A handler built by hand, so the provider's hook has not seen it.
    $inner = new Handler(app());

    app()->singleton(ExceptionHandler::class, fn (): ExceptionHandler => new class($inner) implements ExceptionHandler
    {
        public function __construct(private readonly ExceptionHandler $inner) {}

        public function report(Throwable $e): void
        {
            $this->inner->report($e);
        }

        public function shouldReport(Throwable $e): bool
        {
            return $this->inner->shouldReport($e);
        }

        public function render($request, Throwable $e)
        {
            return $this->inner->render($request, $e);
        }

        public function renderForConsole($output, Throwable $e): void
        {
            $this->inner->renderForConsole($output, $e);
        }
    });
    app(ExceptionHandler::class);

    report(new RuntimeException('through'));

    expect(countedNow()['errors.total'])->toBe(1);
});

it('counts on a handler that replaces the app handler after boot', function (): void {
    app(ExceptionHandler::class);

    app()->singleton(ExceptionHandler::class, fn ($app): ExceptionHandler => new Handler($app));
    app(ExceptionHandler::class);

    report(new RuntimeException('replaced'));

    expect(countedNow()['errors.total'])->toBe(1);
});

it('does not take an entry script as the location', function (string $script): void {
    $e = new RuntimeException('x');
    $trace = new ReflectionProperty(Exception::class, 'trace');

    // A queued closure: its code is evaluated from a stream, then the
    // framework under vendor runs it, and the worker started at artisan.
    $trace->setValue($e, [
        ['file' => base_path('vendor/laravel/framework/src/Illuminate/Queue/CallQueuedClosure.php'), 'line' => 40],
        ['file' => base_path($script), 'line' => 16],
    ]);
    (new ReflectionProperty(Exception::class, 'file'))->setValue($e, 'closure://function () { throw new RuntimeException; }');
    (new ReflectionProperty(Exception::class, 'line'))->setValue($e, 3);

    expect(Fingerprint::for($e, base_path())['location'])->toBe('unknown');

    // An in-app frame below the entry script still wins.
    $trace->setValue($e, [
        ['file' => base_path('app/Jobs/Billing.php'), 'line' => 21],
        ['file' => base_path($script), 'line' => 16],
    ]);

    expect(Fingerprint::for($e, base_path())['location'])->toBe('app/Jobs/Billing.php:21');

    // Thrown in the entry script itself.
    (new ReflectionProperty(Exception::class, 'file'))->setValue($e, base_path($script));
    $trace->setValue($e, []);

    expect(Fingerprint::for($e, base_path())['location'])->toBe('unknown');
})->with(['artisan', 'public/index.php', 'server.php']);

it('keeps other files of the same names in the app', function (): void {
    $e = new RuntimeException('x');
    (new ReflectionProperty(Exception::class, 'trace'))->setValue($e, []);
    (new ReflectionProperty(Exception::class, 'file'))->setValue($e, base_path('app/Console/artisan'));
    (new ReflectionProperty(Exception::class, 'line'))->setValue($e, 7);

    expect(Fingerprint::for($e, base_path())['location'])->toBe('app/Console/artisan:7');

    (new ReflectionProperty(Exception::class, 'file'))->setValue($e, base_path('resources/public/index.php'));

    expect(Fingerprint::for($e, base_path())['location'])->toBe('resources/public/index.php:7');
});
