<?php

declare(strict_types=1);

namespace AgentAccessFixture;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Passport;
use LaravelAgentAccess\Actions\Action;
use LaravelAgentAccess\Adapters\Mcp\AgentAccessTool;
use LaravelAgentAccess\Adapters\Mcp\Tools\RequestAccess;
use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;
use LaravelAgentAccess\Rules\GrantRule;

// This provider is loaded only by the disposable loopback smoke harness, never by the package.
final class FixtureUser extends User implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];
}

final class FixtureOrder extends Model
{
    protected $table = 'fixture_orders';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['refunded' => 'boolean'];
    }
}

#[RequiresPermission('orders.refund', ability: 'refund', resourceArgument: 'order_id', resourceType: 'order')]
final class FixtureRefund extends Action
{
    public function key(): string
    {
        return 'orders.refund';
    }

    public function rules(): array
    {
        return ['order_id' => 'required|integer', 'amount_minor' => 'required|integer|min:1', 'currency' => 'required|string|size:3'];
    }

    public function normalize(array $arguments): array
    {
        return ['order_id' => (int) $arguments['order_id'], 'amount_minor' => (int) $arguments['amount_minor'], 'currency' => strtoupper($arguments['currency'])];
    }

    public function resource(array $arguments): FixtureOrder
    {
        return FixtureOrder::query()->findOrFail($arguments['order_id']);
    }

    public function bindableArguments(): array
    {
        return ['amount_minor', 'currency'];
    }

    public function handle(ExecutionContext $context, AccessRequirement $requirement): array
    {
        return DB::transaction(function () use ($requirement): array {
            $order = FixtureOrder::query()->lockForUpdate()->findOrFail($requirement->resourceId);
            if ($order->refunded) {
                throw new \RuntimeException('Already refunded.');
            }
            $order->update(['refunded' => true]);

            return ['refunded' => true];
        });
    }
}

final class FixtureTool extends AgentAccessTool
{
    protected string $name = 'refund_order';

    protected string $action = 'orders.refund';

    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->integer()->required(), 'amount_minor' => $schema->integer()->required(), 'currency' => $schema->string()->required()];
    }
}

final class FixtureServer extends Server
{
    protected string $name = 'Passport integration fixture';

    protected array $tools = [RequestAccess::class, FixtureTool::class];
}

final class FixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)), 'app.debug' => false,
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->app->databasePath('passport-fixture.sqlite'),
            'cache.default' => 'array', 'session.driver' => 'file', 'session.cookie' => 'laa_passport_fixture',
            'session.secure' => false, 'session.lottery' => [0, 100], 'auth.providers.users.model' => FixtureUser::class,
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'], 'auth.defaults.guard' => 'web',
            'agent-access.principal_model' => FixtureUser::class, 'agent-access.http.enabled' => true,
            'agent-access.http.prefix' => 'agent-access/v1', 'agent-access.http.middleware' => ['auth:api', 'throttle:agent-access'],
            'agent-access.role_resolver' => fn ($principal) => [$principal->user->name],
            'agent-access.actions' => [FixtureRefund::class],
            'agent-access.rules' => [GrantRule::named('passport-refund')->forPermission('orders.refund')
                ->grantableBy('finance_manager')->toRole('support')->bindArguments(['amount_minor', 'currency'])]]);
        Passport::loadKeysFrom($this->app->storagePath());
    }

    public function boot(): void
    {
        Gate::define('refund', fn (FixtureUser $user, FixtureOrder $order, array $arguments): Response => $order->refunded || $arguments['amount_minor'] > 10000 || $arguments['currency'] !== 'PLN'
                ? Response::deny() : Response::deny('Permission missing.', IamDecision::MISSING_PERMISSION_CODE));
        Mcp::oauthRoutes();
        Mcp::web('/mcp', FixtureServer::class)->middleware('auth:api');
        Passport::authorizationView(fn (array $parameters) => response()->json(['auth_token' => $parameters['authToken'], 'csrf_token' => csrf_token()]));
        // Test login only: this router is started on loopback and is never installed in a host app.
        Route::get('/_fixture/login/{id}', function (string $id) {
            Auth::guard('web')->login(FixtureUser::query()->findOrFail($id));

            return response()->json(['csrf_token' => csrf_token()]);
        })->middleware('web');
        Route::post('/_fixture/revoke', function (Request $request) {
            $request->user()->token()->revoke();

            return response()->json(['revoked' => true]);
        })->middleware('auth:api');
        Route::get('/_fixture/health', fn () => response()->json(['ready' => true]));
    }
}

function application(string $consumer): Application
{
    $app = require $consumer.'/bootstrap/app.php';
    $app->afterBootstrapping(LoadConfiguration::class, fn ($app) => $app->register(FixtureProvider::class));

    return $app;
}
