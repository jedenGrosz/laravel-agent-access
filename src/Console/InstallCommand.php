<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Console;

use Illuminate\Console\Command;
use LaravelAgentAccess\AgentAccessServiceProvider;

final class InstallCommand extends Command
{
    protected $signature = 'agent-access:install {--force : Overwrite published files}';

    protected $description = 'Publish Agent Access configuration and migrations; do not run migrations.';

    public function handle(): int
    {
        $code = $this->call('vendor:publish', ['--provider' => AgentAccessServiceProvider::class,
            '--tag' => ['agent-access-config', 'agent-access-migrations'], '--force' => (bool) $this->option('force')]);
        if ($code === self::SUCCESS) {
            $this->info('Configure actions and grant rules, then run php artisan migrate.');
        }

        return $code;
    }
}
