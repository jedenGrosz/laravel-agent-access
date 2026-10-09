<?php

declare(strict_types=1);

namespace Tests;

use LaravelAgentAccess\AgentAccessServiceProvider;
use Orchestra\Testbench\TestCase;

final class InstallerTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir().'/laa-publish-'.bin2hex(random_bytes(8));
        mkdir($this->fixture.'/config', 0777, true);
        mkdir($this->fixture.'/database/migrations', 0777, true);
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [AgentAccessServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useConfigPath($this->fixture.'/config');
        $app->useDatabasePath($this->fixture.'/database');
        $app['config']->set('database.migrations.update_date_on_publish', true);
    }

    public function test_repeated_install_keeps_one_migration_and_preserves_config_without_force(): void
    {
        $this->artisan('agent-access:install')->assertSuccessful();
        $file = $this->fixture.'/config/agent-access.php';
        $custom = file_get_contents($file)."\n// host customization\n";
        file_put_contents($file, $custom);
        $this->artisan('agent-access:install')->assertSuccessful();
        self::assertSame($custom, file_get_contents($file));
        self::assertCount(1, glob($this->fixture.'/database/migrations/*_create_agent_access_tables.php'));
        self::assertCount(1, glob($this->fixture.'/database/migrations/*_add_agent_access_lookup_indexes.php'));
        $this->artisan('agent-access:install', ['--force' => true])->assertSuccessful();
        self::assertNotSame($custom, file_get_contents($file));
        self::assertCount(1, glob($this->fixture.'/database/migrations/*_create_agent_access_tables.php'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (! str_starts_with($this->fixture, sys_get_temp_dir().'/laa-publish-')) {
            throw new \LogicException('Unexpected fixture path.');
        }
        foreach ([$this->fixture.'/config/*.php', $this->fixture.'/database/migrations/*.php'] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                unlink($file);
            }
        }
        rmdir($this->fixture.'/database/migrations');
        rmdir($this->fixture.'/database');
        rmdir($this->fixture.'/config');
        rmdir($this->fixture);
    }
}
