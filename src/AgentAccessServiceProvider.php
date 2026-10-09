<?php

declare(strict_types=1);

namespace LaravelAgentAccess;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Adapters\Laravel\CallbackRoleResolver;
use LaravelAgentAccess\Adapters\Laravel\LaravelPrincipalResolver;
use LaravelAgentAccess\Audit\DatabaseAuditWriter;
use LaravelAgentAccess\Console\InstallCommand;
use LaravelAgentAccess\Console\ManagementCommand;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Contracts\PrincipalResolver;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Rules\RoleHierarchy;
use LaravelAgentAccess\Rules\RuleRegistry;

final class AgentAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-access.php', 'agent-access');
        $this->app->bind(IamAdapter::class, fn ($app) => $app->make(config('agent-access.iam_adapter')));
        $this->app->bind(RoleResolver::class, function ($app) {
            $resolver = config('agent-access.role_resolver');

            return $resolver instanceof \Closure ? new CallbackRoleResolver($resolver) : $app->make($resolver);
        });
        $this->app->bind(PrincipalResolver::class, LaravelPrincipalResolver::class);
        $this->app->bind(AuditWriter::class, DatabaseAuditWriter::class);
        $this->app->singleton(RuleRegistry::class, fn () => new RuleRegistry(config('agent-access.rules', [])));
        $this->app->singleton(ActionRegistry::class, fn ($app) => new ActionRegistry($app, config('agent-access.actions', [])));
        $this->app->singleton(RoleHierarchy::class, fn () => new RoleHierarchy(config('agent-access.hierarchy', [])));
    }

    public function boot(): void
    {
        $this->app->booted(fn () => $this->app->make(ConfigurationValidator::class)->validate());
        if ($this->app->runningInConsole()) {
            $commands = [InstallCommand::class];
            foreach (['validate', 'hierarchy', 'check', 'request', 'requests', 'show', 'approve', 'reject', 'cancel', 'grants', 'revoke', 'expire'] as $operation) {
                $commands[] = new ManagementCommand($operation);
            }
            $this->commands($commands);
        }
        $this->app->make(RuleRegistry::class);
        $this->app->make(RoleHierarchy::class);
        $this->app->make(ActionRegistry::class);
        if ((int) config('agent-access.grants.default_ttl') < 1 || (int) config('agent-access.grants.max_ttl') < 1 ||
            (int) config('agent-access.requests.ttl') < 1 || (int) config('agent-access.grants.default_uses') < 1) {
            throw new \InvalidArgumentException('Access TTLs and default uses must be positive.');
        }
        RateLimiter::for('agent-access', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        $this->publishes([__DIR__.'/../config/agent-access.php' => config_path('agent-access.php')], 'agent-access-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'agent-access-migrations');
        if (config('agent-access.http.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/agent-access.php');
        }
    }
}
