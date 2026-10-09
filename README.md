# Laravel Agent Access

Controlled, explainable privilege grants for agents acting on behalf of authenticated Laravel users.

The existing IAM defines roles, permissions and baseline access. This package adds explicit grant authority, scoped grants, approval requests and a database audit trail. PHP, HTTP, MCP and Artisan use the same application services. No external IAM service or LLM decision is required.

**V0.1 release candidate; not published on Packagist.** PHP 8.3+, Laravel 12.69.0+/13.30.0+. MCP and Spatie are optional integrations.

Start with the [host integration guide](docs/integration.md) for native Gate, Spatie, Sanctum, MCP and existing Passport. Local release preparation is described in [the release guide](docs/release.md).

See [IAM inspection](docs/iam-inspection.md) for hierarchy navigation, ranked grantor roles and a side-effect-free grant simulator through PHP, HTTP, MCP and Artisan.

The [action catalog](docs/action-catalog.md) documents action discovery and visibility query filtering. Minimum Laravel versions include upstream security fixes; see [compatibility verification](docs/verification.md).

Use [execution receipts](docs/execution-receipts.md) to retrieve durable admission/outcome evidence without retrying an action. The optional `run` operation returns a receipt envelope while existing `execute` keeps its result shape.

Use [installation diagnostics](docs/installation-doctor.md) to check a host's schema without changing it. The [roadmap](docs/roadmap.md) separates candidate functionality from public-release tasks and later IAM extensions.

## Run the Orders demo

```sh
composer install
composer demo:prepare
php vendor/bin/testbench agent-access:validate
composer demo
```

`demo:prepare` resets only the local demo database and seeds users: support (ID 1), finance_manager (ID 2), administrator (ID 3), and order 431. The administrator has no implicit authority. SQLite is stored in `workbench/database/database.sqlite`.

Create local, one-hour Sanctum tokens in your terminal:

```sh
php vendor/bin/testbench demo:token 1
php vendor/bin/testbench demo:token 2
```

Use the support token for `/agent-access/v1` or `/mcp`, and the manager token for approvals. Tokens are credentials; they are never included in the package audit. See [the demo walkthrough](docs/demo.md) for the complete HTTP/MCP flow.

## Install into a host application

Until publication, configure a Composer path repository pointing to this checkout and require `jedengrosz/laravel-agent-access` with `@dev`. The Composer vendor is jedengrosz.

```sh
php artisan agent-access:install
# Configure actions, role resolver, grant rules and your existing Policies.
php artisan agent-access:validate
php artisan migrate
```

The installer publishes configuration and migrations. It never runs migrations and never overwrites published files without `--force`.

The default HTTP adapter is disabled. MCP, Sanctum and Spatie are not runtime requirements of the core. Your application provides an Eloquent authenticatable model; IDs may be integer, UUID or ULID. V0.1 resolves one configured user identity type, not autonomous agents. The default principal resolver rejects other authenticatable model classes even when their IDs collide.

## IAM outcomes

The Laravel adapter calls `Gate::forUser()->inspect()`:

| Outcome | Meaning |
| --- | --- |
| `ALLOW` | Existing IAM allows access; no grant is consumed. |
| `MISSING_PERMISSION` | Only an explicitly marked missing permission may be supplemented by a grant. |
| `FORBIDDEN` | A hard denial; grants cannot bypass it. |

Mark a missing permission in the host Policy:

```php
use Illuminate\Auth\Access\Response;
use LaravelAgentAccess\Authorization\IamDecision;

public function refund(User $user, Order $order, array $arguments = []): Response
{
    if ($user->is_suspended || $order->is_refunded) {
        return Response::deny();
    }

    return $user->can('orders.refund')
        ? Response::allow()
        : Response::deny('Permission is required.', IamDecision::MISSING_PERMISSION_CODE);
}
```

An ordinary `false` or unmarked denial is `FORBIDDEN`. Adapter exceptions fail closed. No global `Gate::before` bypass is installed.

## Host actions

Business logic stays in the host application. Register classes extending `LaravelAgentAccess\Actions\Action` in `agent-access.actions`. Each class has exactly one requirement:

```php
#[RequiresPermission(
    'orders.refund',
    ability: 'refund',
    resourceArgument: 'order_id',
    resourceType: 'order',
)]
final class RefundOrder extends Action
{
    public function key(): string { return 'orders.refund'; }
    public function rules(): array {
        return ['order_id' => 'required|integer', 'amount_minor' => 'required|integer|min:1', 'currency' => 'required|string|size:3'];
    }
    public function normalize(array $input): array {
        return ['order_id' => (int) $input['order_id'], 'amount_minor' => (int) $input['amount_minor'], 'currency' => strtoupper($input['currency'])];
    }
    public function resource(array $input): Order { return Order::findOrFail($input['order_id']); }
    public function bindableArguments(): array { return ['amount_minor', 'currency']; }
    public function handle(ExecutionContext $context, AccessRequirement $requirement): array {
        // Recheck business invariants at the write, implement provider idempotency,
        // and perform the business operation using $requirement->arguments.
        return ['refunded' => true];
    }
}
```

Imports omitted above: `Action`, `ExecutionContext`, `AccessRequirement`, `RequiresPermission` are under this package's Actions, Identity and Requirements namespaces. A complete implementation with a locked order and unique refund record is in [the Workbench](workbench/app/Actions/RefundOrder.php).

The server validates and normalizes input, resolves the resource and passes the same normalized arguments to the handler. Unknown action classes cannot be invoked remotely. V0.1 supports one permission and one optional resource per action.

## Grant authority and hierarchy

Define rules in PHP/configuration:

```php
GrantRule::named('finance-refund-support')
    ->forPermission('orders.refund')
    ->grantableBy('finance_manager')
    ->toRole('support')
    ->resourceScoped()
    ->maxDuration(seconds: 900)
    ->maxUses(1)
    ->bindArguments(['amount_minor', 'currency'])
    ->condition(SameDepartment::class);
```

`SameDepartment` implements `GrantCondition`: `requestable(target, requirement, constraints)` checks actor-independent eligibility; `allows(actor, target, requirement, constraints)` checks the actual grantor. Conditions must enforce any required account, organization or resource restrictions. Their actor-independent part is also checked when a grant is used.

Resolve roles using a class implementing `RoleResolver`, or a configured callback accepting `Principal`. For Spatie use `SpatieRoleResolver`; it filters roles by the model's Spatie guard (or `agent-access.roles.spatie_guard`). Baseline authorization still goes through Gate/Policies. The default role resolver returns no roles, hence no escalation.

`hierarchy` is an array of directed role pairs, e.g. `[['administrator', 'finance_manager'], ['finance_manager', 'support']]`. Multiple parents are supported; cycles, duplicates, empty names and self edges are rejected. The hierarchy is advisory. It does not inherit use permissions and cannot exclude an actor otherwise allowed by a complete GrantRule.

A permission's use authority and grant authority are independent. Self approval is forbidden. Business grants cannot grant package management authority. When several rules match, select one `rule_id`; the entire request must satisfy that rule. Limits are never combined across rules.

## Grants and requests

A grant combines action, permission, principal, resource, valid interval, optional usage limit and selected argument bindings. These are independent restrictions, not exclusive grant types.

- Defaults: grant TTL 900 seconds, maximum TTL 86400 seconds, pending-request TTL 3600 seconds, one use.
- Omitting PHP `maxUses` reads the configured default just like the transports. Explicit `maxUses: null` requests unlimited uses; the rule must permit it. Oversized usage limits, including configured defaults, are rejected rather than silently reduced.
- `unlimitedUses()` is explicit; no perpetual grants exist in V0.1.
- `allowGlobalScope()` explicitly permits requirements without a resource. Resource-backed requests still bind their resolved resource; V0.1 does not promote them into wildcard grants.
- Bound arguments are compared with strict type/value equality after host normalization. Use integers for minor-unit money or decimal strings; floats are not accepted as bound values. Arrays/objects are not binding values in V0.1.
- Unbound arguments may change. Bind every argument relevant to the approval, and enforce remaining business constraints in your Policy/handler.
- Requesters request for themselves. Remote identity overrides are prohibited.
- Requests transition from `pending` to `approved`, `rejected`, `cancelled` or `expired`; terminal states do not reopen.
- Idempotency keys are scoped to requester. An equivalent normalized payload replays the request; changed content conflicts.
- Rules have stable IDs and fingerprints covering their definitions, revision and condition source files. Changed/removed rules invalidate existing requests and grants. Bump `version()` when externally configured condition semantics change.
- Potential grantor roles are not a promise that a currently available approver exists.

## PHP services

```php
$context = new ExecutionContext(app(PrincipalResolver::class)->resolve());
$decision = app(DecisionService::class)->check($context, 'orders.refund', $arguments);
$request = app(AccessRequestService::class)->create($context, 'orders.refund', $arguments, idempotencyKey: 'refund-431');
$grant = app(ApprovalService::class)->approve($managerContext, $request->id);
$result = app(ActionExecutor::class)->execute($context, 'orders.refund', $arguments);
```

Only construct explicit principals from trusted server code. `check()` is a preview and never consumes a grant; denied checks are audited. Execution always reevaluates the current IAM and grant.

Eloquent principals are refreshed before each IAM evaluation, including admission, so a retained execution context cannot preserve access after account suspension. Host-specific permission caches remain the IAM adapter's responsibility.

## HTTP, MCP and Artisan

Enable `agent-access.http.enabled` and configure middleware. Default middleware is `auth:sanctum` plus 60 requests/minute/user. The host authentication layer is responsible for credential validity, audience and token abilities; business grants cannot replace that layer.

HTTP endpoints are relative to `/agent-access/v1`:

| Method | Path | Operation |
| --- | --- | --- |
| POST | `/check` | Preview an action/arguments pair |
| POST | `/actions/{action}/execute` | Execute a registered action |
| POST | `/requests` | Create a request |
| GET | `/requests`, `/requests/{id}` | Authorized list/details |
| POST | `/requests/{id}/approve`, `/reject`, `/cancel` | Workflow transitions |
| GET | `/grants` | Authorized grants |
| POST | `/grants/{id}/revoke` | Revoke |

Lists use SQL candidate filters and paginate after full visibility evaluation: default 20, maximum 100. Totals do not include hidden records. V0.1 evaluates PHP visibility policies while scanning the filtered candidate records; a large deployment may need a host-specific query optimization later.

`check`, `execute` and `request` accept `action` and `arguments`; requests additionally accept `rule_id`, `duration_seconds`, `max_uses`, `reason`, `idempotency_key`. HTTP also accepts `Idempotency-Key`. Route parameters determine the target record/action. Hidden records give 404; denied execution 403; validation 422; state/idempotency conflicts 409.

For MCP install `laravel/mcp:^1.0.1`, register the nine tools under `Adapters\Mcp\Tools`, and protect the host server with Sanctum or existing Passport authentication. Extend `AgentAccessTool` for business tools, set its `$action`, and provide its schema. Its final `handle()` invokes the shared executor. An attribute on an arbitrary Laravel Tool alone does not enforce anything.

Tool errors contain `isError: true`, structured data and a text fallback. `ACCESS_REQUIRED` includes the missing requirement, candidate roles and independent grant options. HTTP and PHP expose the same decision model. Requestable tools can remain discoverable before access is granted.

Artisan commands: `agent-access:install`, `validate`, `hierarchy`, `actions`, `check`, `simulate`, `request`, `requests`, `show`, `approve`, `reject`, `cancel`, `grants`, `revoke`, `expire`.

```sh
php artisan agent-access:check --as=52 --input='{"action":"orders.refund","arguments":{"order_id":431,"amount_minor":10000,"currency":"PLN"}}'
php artisan agent-access:approve --as=12 --input='{"id":"REQUEST_ULID"}'
php artisan agent-access:expire --as=12
```

`--as` exists only for trusted local administration, never HTTP/MCP. Commands still enforce all grant policies. Schedule `agent-access:expire` if expiration notifications are needed; access expiration never depends on the scheduler.

## Concurrency, effects and audit

Approval locks the request and commits approval, grant, terminal status and audit together. Database uniqueness additionally prevents duplicate grants. Current grantor roles/conditions are checked again at approval; repeats require the same still-authorized approver.

Execution locks and rechecks the selected grant, increments uses, writes its receipt and `ACTION_STARTED`, and commits **before** calling the handler. One use means one attempt; failure/timeout never restores it. Invoke `ActionExecutor` outside an existing transaction; a handler may start its own transaction.

Revocation/expiration block future admissions and cannot interrupt work already admitted. Host handlers must recheck business invariants at the actual write and use downstream idempotency for external effects. V0.1 does not promise exactly-once remote effects or resume actions automatically.

Audit is allowlisted, on the same default database connection as security changes. No bearer tokens, passwords, headers, full payloads or business results are persisted in audit. The database trail records checks, requests, approval, grants, use receipts and execution outcomes. `AccessEvent` is dispatched after commit, carrying event type, correlation ID and safe references. Prefer queued notification listeners; an after-commit listener failure cannot undo committed security state.

Admission-audit failure blocks the handler and rolls back grant usage. Result-audit failure returns `ACTION_RESULT_UNRECORDED` with execution/correlation IDs; inspect business state instead of blindly retrying. An `ACTION_STARTED` without a terminal event may represent a crash or an uncertain outcome.

## Checks

```sh
composer check
docker compose up -d postgres mysql
docker compose build test
docker compose run --rm test php vendor/bin/phpunit
docker compose run --rm -e LAA_DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 test php vendor/bin/phpunit
docker compose down
```

SQLite skips only independent-process race tests. PostgreSQL/MySQL runs cover simultaneous approvals, one-use execution, approval/cancel and revoke/execute. Containers have isolated temporary databases and no public ports. CI additionally defines PHP 8.3/8.4/8.5 × Laravel 12/13 compatibility checks.
