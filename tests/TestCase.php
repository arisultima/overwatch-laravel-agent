<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Tests;

use Arisultima\OverwatchAgent\OverwatchAgentServiceProvider;
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

    protected function getPackageProviders($app): array
    {
        return [OverwatchAgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.debug', false);
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
}
