<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use LaravelAgentAccess\Adapters\Laravel\SpatieRoleResolver;
use LaravelAgentAccess\Rules\GrantRule;
use Workbench\App\Actions\CancelOrder;
use Workbench\App\Actions\DeleteOrder;
use Workbench\App\Actions\RefundOrder;
use Workbench\App\Actions\ViewOrder;
use Workbench\App\Conditions\SameDepartment;
use Workbench\App\Console\DemoTokenCommand;
use Workbench\App\Mcp\OrdersServer;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;
use Workbench\App\Policies\OrderPolicy;

final class DemoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! defined('PHPUNIT_COMPOSER_INSTALL') && ! getenv('LAA_DB_CONNECTION')) {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => dirname(__DIR__, 2).'/database/database.sqlite']);
        }
        config(['cache.default' => 'array', 'session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
        config(['auth.providers.users.model' => User::class, 'agent-access.principal_model' => User::class,
            'agent-access.role_resolver' => SpatieRoleResolver::class, 'agent-access.http.enabled' => true,
            'agent-access.actions' => [ViewOrder::class, RefundOrder::class, CancelOrder::class, DeleteOrder::class],
            'agent-access.hierarchy' => [['administrator', 'finance_manager'], ['finance_manager', 'support']],
            'agent-access.rules' => [GrantRule::named('finance-refund-support')->forPermission('orders.refund')->grantableBy('finance_manager')
                ->toRole('support')->resourceScoped()->maxDuration(900)->maxUses(1)->bindArguments(['amount_minor', 'currency'])->condition(SameDepartment::class)]]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoTokenCommand::class]);
        }
        Gate::policy(Order::class, OrderPolicy::class);
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        if (class_exists(Mcp::class)) {
            Mcp::web('/mcp', OrdersServer::class)->middleware('auth:sanctum');
        }
    }
}
