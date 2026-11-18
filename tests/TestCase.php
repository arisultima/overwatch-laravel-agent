<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests;

use Arisultima\OverwatchAgent\OverwatchAgentServiceProvider;
use Arisultima\OverwatchAgent\Probes\InstanceStamp;
use Arisultima\OverwatchAgent\Recording\Guard;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots with the package enabled (a token and a url set), on the array cache
 * and the sync queue. The provider decides what to register while it boots,
 * so a test that needs other settings calls bootWith() to boot again.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Overwatch settings applied over the defaults on the next boot.
     *
     * @var array<string, mixed>
     */
    protected array $overwatch = [];

    /**
     * Providers the app registers before this package's, as an app's own
     * providers and auto-discovered ones are.
     *
     * @var list<class-string>
     */
    protected array $earlierProviders = [];

    /**
     * The app environment on the next boot, when it is not "testing".
     */
    protected ?string $environment = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The guard's pause lives for the whole process, so one test that
        // trips it must not silence the next.
        Guard::reset();
        InstanceStamp::reset();
    }

    protected function getPackageProviders($app): array
    {
        // Read just before the providers register, unlike defineEnvironment().
        if ($this->environment !== null) {
            $app['env'] = $this->environment;
        }

        return [...$this->earlierProviders, OverwatchAgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.debug', false);
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('logging.default', 'null');

        $config->set('overwatch.url', 'https://overwatch.test');
        $config->set('overwatch.token', 'satapp_test');

        foreach ($this->overwatch as $key => $value) {
            $config->set('overwatch.'.$key, $value);
        }
    }

    /**
     * Boot the application again with these overwatch settings.
     *
     * @param  array<string, mixed>  $overwatch
     */
    protected function bootWith(array $overwatch): void
    {
        $this->overwatch = $overwatch;

        $this->reloadApplication();
    }

    /**
     * Record on the array store ('cache') or on Redis ('redis'). Redis runs
     * under a cache prefix of its own (plus the given connection prefix) and
     * its keys are deleted afterwards; the test is skipped when no Redis
     * server answers. Returns the cache prefix.
     */
    protected function useDriver(string $driver, string $connectionPrefix = ''): string
    {
        if ($driver !== 'redis') {
            return (string) config('cache.prefix');
        }

        if (! redisReachable()) {
            $this->markTestSkipped('Redis is not reachable at REDIS_HOST.');
        }

        $prefix = useRedisCache($connectionPrefix);

        $this->beforeApplicationDestroyed(fn () => forgetRedisKeys($prefix));

        return $prefix;
    }
}
