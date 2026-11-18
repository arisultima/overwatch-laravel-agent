<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Console;

use Arisultima\OverwatchAgent\Config;
use Illuminate\Console\Command;

/**
 * Publishes the config (never over one the app already has), prints the
 * .env lines to add and says what is still missing. It never writes .env:
 * the token is a secret the app owner puts there.
 *
 * Exits 1 while something stops reports going out, so a setup script can
 * tell an app that is ready from one that is not.
 */
final class InstallCommand extends Command
{
    protected $signature = 'overwatch:install';

    protected $description = 'Publish the Overwatch config and show the .env lines to add';

    public function handle(): int
    {
        $this->publishConfig();

        $this->newLine();
        $this->line('Add these lines to .env (this command never writes it):');
        $this->newLine();
        $this->line('OVERWATCH_URL=');
        $this->line('OVERWATCH_TOKEN=');
        $this->line('# Optional: the name this machine reports under. Leave it out to use the hostname.');
        $this->line('OVERWATCH_INSTANCE=');
        $this->newLine();
        $this->line('Reports go once a minute from the Laravel scheduler, so it must run: a cron entry');
        $this->line('for "php artisan schedule:run" every minute, or "php artisan schedule:work".');

        if ($this->laravel->configurationIsCached()) {
            $this->newLine();
            $this->components->warn('The config is cached. Run "php artisan config:cache" again after editing .env.');
        }

        $missing = Config::missing();

        if ($missing !== []) {
            $this->newLine();

            foreach ($missing as $line) {
                $this->components->error($line);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Ready. Run "php artisan overwatch:test" to send one report and see the answer.');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $path = config_path('overwatch.php');

        if (file_exists($path)) {
            $this->components->info('config/overwatch.php is already there; it was left as it is.');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'overwatch-config']);

        if (file_exists($path)) {
            $this->components->info('Published config/overwatch.php.');
        } else {
            $this->components->warn('Could not publish config/overwatch.php. The defaults apply until it exists.');
        }
    }
}
