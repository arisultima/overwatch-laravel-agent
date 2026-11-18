<?php

declare(strict_types=1);

use Arisultima\OverwatchAgent\Report\ReportBuilder;
use Arisultima\OverwatchAgent\Report\Trimmer;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * A job whose payload names a person, failing with a message that names
 * them again.
 */
final class PrivacyMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $email = 'grace@example.com') {}

    public function handle(): void
    {
        throw new RuntimeException('could not mail '.$this->email);
    }
}

/**
 * What must never leave the app, whatever it did.
 */
const PRIVATE_VALUES = [
    '@example.com', '192.0.2.44', '4111111111111111', 'token=abc', "RuntimeException('",
    // The failed sign-ins' password, and the id and name of the account that signed in.
    'hunter2', '01JZPRIVACYUSER0000000042', 'Ivan Petrov',
];

/**
 * A busy minute of an app full of personal data: requests with a query
 * string, failed sign-ins from one address, a sign-in by a named account, a
 * reported exception, a slow query with bindings and a failing job, all at
 * 13:05.
 */
function noisyMinute(): void
{
    as_instance('web-1');
    at('13:05:20');

    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'testing',
        'queue.failed.table' => 'failed_jobs',
    ]);

    test()->loadLaravelMigrations(['--database' => 'testing']);

    Route::get('/search', fn () => 'found');
    Route::post('/login', function () {
        event(new Failed('web', null, ['email' => 'dave@example.com', 'password' => 'hunter2']));

        return response('wrong password', 422);
    })->name('login');

    test()->get('/search?email=carol@example.com&token=abc')->assertOk();

    foreach ([1, 2, 3] as $attempt) {
        test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
            ->post('/login?email=dave@example.com&token=abc')
            ->assertStatus(422);
    }

    event(new Login('web', new GenericUser([
        'id' => '01JZPRIVACYUSER0000000042',
        'name' => 'Ivan Petrov',
        'email' => 'ivan@example.com',
    ]), false));

    report(new RuntimeException('card 4111111111111111 for erin@example.com'));

    event(new QueryExecuted('select * from users where email = ?', ['frank@example.com'], 900.0, DB::connection('testing')));

    dispatch(new PrivacyMailJob)->onConnection('database')->onQueue('mail');

    test()->artisan('queue:work', [
        'connection' => 'database',
        '--queue' => 'mail',
        '--stop-when-empty' => true,
        '--tries' => 1,
        '--sleep' => 0,
    ])->assertExitCode(0);

    // A second job waits on the queue, its payload naming someone too.
    dispatch(new PrivacyMailJob('heidi@example.com'))->onConnection('database')->onQueue('default');
}

it('a report from a noisy app carries no personal data', function (): void {
    noisyMinute();
    config(['queue.default' => 'database', 'overwatch.queues' => ['default', 'mail']]);

    $built = app(ReportBuilder::class)->build(at('13:06:15'));
    // Slashes unescaped, so a path in the report would read as one.
    $json = json_encode($built['report'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $body = Trimmer::encode($built['report']);

    $minute = instanceIn($built['report'], 'web-1')['minutes'][0];

    // Everything happened and was counted, so the report had the chance to leak.
    expect($minute['counters']['http.requests'])->toBe(4)
        ->and($minute['counters']['auth.login_failed'])->toBe(3)
        ->and($minute['counters']['auth.login_failed_unknown'])->toBe(3)
        ->and($minute['counters']['auth.login'])->toBe(1)
        ->and($minute['distinct']['auth.login_accounts'])->toBe(1)
        ->and($minute['counters']['errors.total'])->toBeGreaterThanOrEqual(2)
        ->and($minute['counters']['db.slow_queries'])->toBe(1)
        ->and($minute['counters']['jobs.failed'])->toBe(1)
        ->and($minute['distinct']['auth.login_failed_accounts'])->toBe(1)
        ->and($minute['distinct']['auth.login_failed_sources'])->toBe(1)
        ->and($minute['distinct']['auth.login_failed_max_per_source'])->toBe(3)
        ->and($built['report']['checks']['queue']['pending'])->toBe(1)
        ->and($built['report']['checks']['queue']['failed'])->toBe(1);

    foreach ([$json, $body] as $text) {
        foreach (PRIVATE_VALUES as $value) {
            expect($text)->not->toContain($value);
        }

        // No server path either: error locations are relative to the app.
        expect($text)->not->toContain(dirname(__DIR__, 2));
    }
});

it('sends nothing personal over the wire either', function (): void {
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    noisyMinute();
    at('13:06:15');

    Artisan::call('overwatch:test', ['--dry-run' => true]);
    $printed = Artisan::output();

    Artisan::call('overwatch:report');

    $sent = (string) gzdecode(Http::recorded()->last()[0]->body());

    expect(json_decode($sent, true)['instances'][0]['minutes'])->not->toBe([]);

    foreach ([$printed, $sent] as $text) {
        foreach (PRIVATE_VALUES as $value) {
            expect($text)->not->toContain($value);
        }

        expect($text)->not->toContain(dirname(__DIR__, 2));
    }
});

it('carries no private hostnames', function (): void {
    $root = dirname(__DIR__, 2);
    // Words no file may contain, by length, kept only as sha256 hashes so
    // this file names none of them.
    $banned = [
        7 => ['c504fcff7a86d473347ce0db02aed638926adbb404ca2cc587645d12ad800d1b'],
        12 => ['0667ae780377a54bb136b5cc61e207daf108e19fad795b2cec33aea35bb22019'],
        19 => ['1b74608e1514b0f19b9985c372538a0c901d131772eccdbe38be44dcdf8b173f'],
    ];
    $skip = ['vendor', '.git', '.phpunit.cache'];
    $scanned = 0;

    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), $skip, true)),
    ));

    foreach ($files as $file) {
        preg_match_all('/[a-z0-9]+/', strtolower((string) file_get_contents($file->getPathname())), $runs);
        $scanned++;
        $found = [];

        // Every run of letters and digits, checked at every offset.
        foreach (array_unique($runs[0]) as $run) {
            foreach ($banned as $length => $hashes) {
                for ($i = 0; $i + $length <= strlen($run); $i++) {
                    if (in_array(hash('sha256', substr($run, $i, $length)), $hashes, true)) {
                        $found[] = substr($run, $i, $length);
                    }
                }
            }
        }

        expect($found)->toBe([], substr($file->getPathname(), strlen($root) + 1).' names a private hostname');
    }

    // src/, config/, docs/, tests/ and the root files at the least.
    expect($scanned)->toBeGreaterThan(50)
        ->and(is_file($root.'/README.md'))->toBeTrue();
});
