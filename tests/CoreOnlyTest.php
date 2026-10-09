<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Mcp\Server\McpServiceProvider;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\AgentAccessServiceProvider;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Rules\RuleRegistry;
use Orchestra\Testbench\TestCase;
use Spatie\Permission\PermissionServiceProvider;

final class CoreOnlyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AgentAccessServiceProvider::class];
    }

    public function test_core_boots_without_registering_optional_providers(): void
    {
        self::assertSame([], app(ActionRegistry::class)->all());
        self::assertSame([], app(RuleRegistry::class)->all());
        self::assertInstanceOf(IamAdapter::class, app(IamAdapter::class));
        self::assertFalse($this->app->getLoadedProviders()[McpServiceProvider::class] ?? false);
        self::assertFalse($this->app->getLoadedProviders()[PermissionServiceProvider::class] ?? false);
        $this->getJson('/agent-access/v1/requests')->assertNotFound();
    }
}
