<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use LaravelAgentAccess\Actions\Action;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Adapters\Laravel\CallbackRoleResolver;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;
use LaravelAgentAccess\Rules\GrantRule;
use LaravelAgentAccess\Rules\RuleRegistry;
use Spatie\Permission\Models\Role;

// Run against an isolated, freshly created Laravel app with only the package's runtime dependencies.
try {
    $consumer = $argv[1];
    require $consumer.'/vendor/autoload.php';
    $app = require $consumer.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $file = $consumer.'/database/agent-access-smoke.sqlite';
    if (! is_file($file)) {
        touch($file);
    }
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file, 'cache.default' => 'array',
        'session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
    DB::purge();
    $kernel->call('agent-access:install');
    $kernel->call('migrate', ['--force' => true]);

    final class SmokeOrder extends Model
    {
        protected $table = 'smoke_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    #[RequiresPermission('orders.refund', ability: 'refund', resourceArgument: 'order_id', resourceType: 'order')]
    final class SmokeRefund extends Action
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
            $arguments['order_id'] = (int) $arguments['order_id'];
            $arguments['amount_minor'] = (int) $arguments['amount_minor'];

            return $arguments;
        }

        public function resource(array $arguments): SmokeOrder
        {
            return SmokeOrder::query()->findOrFail($arguments['order_id']);
        }

        public function bindableArguments(): array
        {
            return ['amount_minor', 'currency'];
        }

        public function handle(ExecutionContext $context, AccessRequirement $requirement): array
        {
            $changed = SmokeOrder::query()->where('id', $requirement->resourceId)->where('refunded', false)->update(['refunded' => true]);
            if ($changed !== 1) {
                throw new RuntimeException('Duplicate effect.');
            }

            return ['refunded' => true];
        }
    }

    if (! Schema::hasTable('smoke_orders')) {
        Schema::create('smoke_orders', function ($t) {
            $t->id();
            $t->boolean('refunded')->default(false);
        });
    }
    $support = User::query()->firstOrCreate(['email' => 'support@example.test'], ['name' => 'support', 'password' => 'unused']);
    $manager = User::query()->firstOrCreate(['email' => 'manager@example.test'], ['name' => 'finance_manager', 'password' => 'unused']);
    SmokeOrder::query()->updateOrCreate(['id' => 431], ['refunded' => false]);
    app(ActionRegistry::class)->register(new SmokeRefund);
    app(RuleRegistry::class)->register(GrantRule::named('smoke-refund')->forPermission('orders.refund')
        ->grantableBy('finance_manager')->toRole('support')->bindArguments(['amount_minor', 'currency']));
    app()->instance(RoleResolver::class, new CallbackRoleResolver(fn ($p) => [$p->user->name]));
    Gate::define('refund', fn ($user, $order) => $order->refunded ? Response::deny()
        : Response::deny('Missing permission.', IamDecision::MISSING_PERMISSION_CODE));
    $ctx = new ExecutionContext(new Principal($support));
    $managerCtx = new ExecutionContext(new Principal($manager));
    $args = ['order_id' => 431, 'amount_minor' => 10000, 'currency' => 'PLN'];
    $request = app(AccessRequestService::class)->create($ctx, 'orders.refund', $args);
    $grant = app(ApprovalService::class)->approve($managerCtx, $request->id);
    $result = app(ActionExecutor::class)->execute($ctx, 'orders.refund', $args);
    if (! $result['refunded'] || $grant->fresh()->uses !== 1 || class_exists(Server::class) || class_exists(Role::class)) {
        throw new RuntimeException('Fresh consumer smoke test failed.');
    }
    echo "Fresh Laravel consumer: install, migration, approval and one-use refund passed without MCP or Spatie.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Fresh consumer smoke failed: '.($error::class === RuntimeException::class ? $error->getMessage() : $error::class).PHP_EOL);
    exit(1);
}
