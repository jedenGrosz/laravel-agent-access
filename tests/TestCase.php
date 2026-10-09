<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use LaravelAgentAccess\AgentAccessServiceProvider;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\Principal;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;
use Workbench\App\Providers\DemoServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected User $support;

    protected User $manager;

    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class, SanctumServiceProvider::class,
            AgentAccessServiceProvider::class, McpServiceProvider::class, DemoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $connection = getenv('LAA_DB_CONNECTION') ?: 'sqlite';
        $app['config']->set('database.default', $connection);
        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite.database', ':memory:');
        } else {
            $app['config']->set('database.connections.'.$connection, ['driver' => $connection, 'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT'), 'database' => getenv('LAA_DB_DATABASE') ?: 'agent_access', 'username' => getenv('DB_USERNAME') ?: 'agent_access',
                'password' => getenv('DB_PASSWORD') ?: 'local-test-only', 'charset' => $connection === 'mysql' ? 'utf8mb4' : 'utf8', 'prefix' => '', 'strict' => true]);
        }
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['migrator']->path(__DIR__.'/../database/migrations');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        foreach (['orders.view', 'orders.refund', 'orders.cancel', 'orders.delete'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('support', 'web')->givePermissionTo('orders.view');
        Role::findOrCreate('finance_manager', 'web');
        Role::findOrCreate('administrator', 'web');
        $this->support = User::query()->create(['name' => 'Support', 'email' => 'support@example.test', 'password' => 'unused']);
        $this->support->assignRole('support');
        $this->manager = User::query()->create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'unused']);
        $this->manager->assignRole('finance_manager');
        Order::query()->create(['id' => 431, 'amount_minor' => 20000, 'currency' => 'PLN']);
    }

    protected function context(?User $user = null, string $channel = 'php'): ExecutionContext
    {
        return new ExecutionContext(new Principal($user ?? $this->support), $channel);
    }

    protected function args(int $id = 431, int $amount = 10000): array
    {
        return ['order_id' => $id, 'amount_minor' => $amount, 'currency' => 'PLN'];
    }
}
