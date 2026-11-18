<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->freezeTime();
});

it('counts a request by status class and duration bucket', function (): void {
    Route::get('/ok', fn () => 'ok');

    $this->get('/ok')->assertOk();

    expect(countedNow())->toBe([
        'histogram.le_50' => 1,
        'http.2xx' => 1,
        'http.requests' => 1,
    ]);
});

it('counts 403, 419 and 429 separately', function (): void {
    foreach ([403, 419, 429] as $status) {
        Route::get('/status-'.$status, fn () => abort($status));
    }

    $this->get('/status-403')->assertStatus(403);
    $this->get('/status-419')->assertStatus(419);
    $this->get('/status-429')->assertStatus(429);

    $counted = countedNow();

    expect($counted['http.requests'])->toBe(3)
        ->and($counted['http.4xx'])->toBe(3)
        ->and($counted['http.403'])->toBe(1)
        ->and($counted['http.419'])->toBe(1)
        ->and($counted['http.429'])->toBe(1)
        ->and($counted)->not->toHaveKey('http.401')
        ->and($counted)->not->toHaveKey('auth.login_throttled');
});

it('counts a 401 and a 5xx', function (): void {
    Route::get('/denied', fn () => response('no', 401));
    Route::get('/broken', fn () => response('down', 503));

    $this->get('/denied')->assertStatus(401);
    $this->get('/broken')->assertStatus(503);

    $counted = countedNow();

    expect($counted['http.401'])->toBe(1)
        ->and($counted['http.4xx'])->toBe(1)
        ->and($counted['http.5xx'])->toBe(1);
});

it('counts a throttled sign-in', function (): void {
    Route::post('/login', fn () => response('slow down', 429))->name('login');
    Route::get('/search', fn () => response('slow down', 429))->name('search');

    $this->post('/login')->assertStatus(429);
    $this->get('/search')->assertStatus(429);

    $counted = countedNow();

    expect($counted['auth.login_throttled'])->toBe(1)
        ->and($counted['http.429'])->toBe(2);
});

it('does not count a sign-in that is not throttled', function (): void {
    Route::post('/login', fn () => 'welcome')->name('login');

    $this->post('/login')->assertOk();

    expect(countedNow())->not->toHaveKey('auth.login_throttled');
});

it('counts a slow request', function (): void {
    config(['overwatch.slow_request_ms' => 10]);

    Route::get('/slow', function () {
        usleep(20_000);

        return 'done';
    });

    $this->get('/slow')->assertOk();

    $counted = countedNow();

    expect($counted['http.slow'])->toBe(1)
        ->and(($counted['histogram.le_50'] ?? 0) + ($counted['histogram.le_100'] ?? 0))->toBe(1);
});

it('does not count a fast request as slow', function (): void {
    Route::get('/fast', fn () => 'ok');

    $this->get('/fast')->assertOk();

    expect(countedNow())->not->toHaveKey('http.slow');
});

it('never changes the response', function (): void {
    Route::get('/teapot', fn () => response('short and stout', 418, ['X-Brew' => 'yes']));

    $this->get('/teapot')
        ->assertStatus(418)
        ->assertHeader('X-Brew', 'yes')
        ->assertSeeText('short and stout');
});

it('counts nothing when disabled', function (): void {
    $this->bootWith(['token' => null]);
    $this->freezeTime();

    Route::get('/ok', fn () => 'ok');

    $this->get('/ok')->assertOk();

    expect(countedNow())->toBe([]);
});

it('serves requests, queries, jobs and reports while the store is broken', function (): void {
    config(['overwatch.cache_store' => 'missing-store']);

    Route::get('/ok', fn () => 'ok');

    $this->get('/ok')->assertOk();

    event(new \Illuminate\Database\Events\QueryExecuted('select 1', [], 900.0, \Illuminate\Support\Facades\DB::connection()));
    report(new RuntimeException('still fine'));
    dispatch(fn () => null);

    expect(\Arisultima\OverwatchAgent\Recording\Guard::paused())->toBeTrue();
});
