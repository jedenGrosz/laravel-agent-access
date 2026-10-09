<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Adapters\Laravel\CallbackRoleResolver;
use LaravelAgentAccess\AgentAccessServiceProvider;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Rules\GrantRule;
use LaravelAgentAccess\Rules\RuleRegistry;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\BoundValueAction;
use Tests\Fixtures\StringUser;

final class BindingIdentityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AgentAccessServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('agent-access.principal_model', StringUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['migrator']->path(__DIR__.'/../database/migrations');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('string_users', function (Blueprint $table): void {
            $table->string('id', 128)->primary();
            $table->string('role');
        });
        app(ActionRegistry::class)->register(new BoundValueAction);
        app(RuleRegistry::class)->register(GrantRule::named('bound-value')->forPermission('fixture.bound')->allowGlobalScope()
            ->grantableBy('manager')->toRole('support')->bindArguments(['value']));
        app()->instance(RoleResolver::class, new CallbackRoleResolver(fn ($principal) => [$principal->user->role]));
        Gate::define('fixture.bind', fn () => Response::deny('Missing.', IamDecision::MISSING_PERMISSION_CODE));
    }

    #[DataProvider('stringIds')]
    public function test_uuid_and_ulid_principals_complete_grant_execution_without_integer_coercion(string $supportId, string $managerId): void
    {
        StringUser::query()->create(['id' => $supportId, 'role' => 'support']);
        StringUser::query()->create(['id' => $managerId, 'role' => 'manager']);
        $support = new ExecutionContext(app(PrincipalDirectory::class)->find($supportId));
        $manager = new ExecutionContext(app(PrincipalDirectory::class)->find($managerId));
        $request = app(AccessRequestService::class)->create($support, 'fixture.bound', ['value' => 10]);
        self::assertSame($supportId, $request->principal_id);
        $grant = app(ApprovalService::class)->approve($manager, $request->id);
        self::assertSame($managerId, $grant->getAttribute('grantor_id'));
        self::assertFalse(app(DecisionService::class)->check($support, 'fixture.bound', ['value' => '10'])->allowed);
        self::assertFalse(app(DecisionService::class)->check($support, 'fixture.bound', ['value' => true])->allowed);
        self::assertTrue(app(DecisionService::class)->check($support, 'fixture.bound', ['value' => 10])->allowed);
        self::assertSame(['value' => 10], app(ActionExecutor::class)->execute($support, 'fixture.bound', ['value' => 10]));
        self::assertSame(1, $grant->fresh()->uses);
    }

    public static function stringIds(): iterable
    {
        yield 'UUID' => ['3ae4cd90-7a20-4a98-9c2c-4b0b3f078fa1', '4ae4cd90-7a20-4a98-9c2c-4b0b3f078fa2'];
        yield 'ULID' => ['01K72NYQT5MTXC9R21K6VN6B5A', '01K72NYQT5MTXC9R21K6VN6B5B'];
    }

    #[DataProvider('unsupportedBindings')]
    public function test_non_exact_binding_types_cannot_be_requested(mixed $value): void
    {
        $user = StringUser::query()->create(['id' => 'support-id', 'role' => 'support']);
        $context = new ExecutionContext(new Principal($user));
        $this->expectException(is_object($value) ? \InvalidArgumentException::class : AccessException::class);
        app(AccessRequestService::class)->create($context, 'fixture.bound', ['value' => $value], ruleId: 'bound-value');
    }

    public static function unsupportedBindings(): iterable
    {
        yield 'float' => [10.0];
        yield 'array' => [[10]];
        yield 'object' => [(object) ['value' => 10]];
    }
}
