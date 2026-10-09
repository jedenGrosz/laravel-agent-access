# Integrating Laravel Agent Access

Use this guide after installing the package from a local Composer path repository. The package extends your IAM; your application supplies users, roles, Policies, resources and business handlers.

## Native Gate and Policies

Set `principal_model` to your application's authenticatable Eloquent model and `principal_type` to a stable identity name. The default resolver accepts that exact model class. The identity name and user ID are persisted on requests and grants; changing either requires a deliberate data migration.

Register the host Policy as usual in a service provider:

```php
use App\Models\Order;
use App\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;

Gate::policy(Order::class, OrderPolicy::class);
```

The action's `#[RequiresPermission]` separates the permission identifier (`orders.refund`) from the Policy ability (`refund`). Gate receives the resolved resource and normalized arguments. Resource-less abilities receive the arguments array.

Use `Response::deny($message, IamDecision::MISSING_PERMISSION_CODE)` only for an otherwise eligible user who lacks the permission. Suspended accounts, inaccessible resources and business prohibitions return ordinary denials. Custom IAM adapters implement `IamAdapter` and return the same three outcomes; adapter exceptions block access.

Roles are independent of Gate. For an existing native role relationship, provide a class implementing `RoleResolver`:

```php
namespace App\AgentAccess;

use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\Principal;

final class ExistingRoles implements RoleResolver
{
    public function roles(Principal $principal): array
    {
        return $principal->user->roles()->pluck('name')->all();
    }
}
```

Configure `'role_resolver' => ExistingRoles::class`. Adapt the relationship/query to your IAM, including its organization or guard constraints. Class-based resolvers work with `config:cache`; a Closure in configuration does not.

An existing permission such as `orders.refund` allows execution through Gate. A GrantRule separately allows a finance manager to approve a restricted refund for support. Possessing either permission does not imply the other authority. A hierarchy edge adds neither authority.

## Existing Spatie IAM

If the host already uses Spatie's `HasRoles`, configure:

```php
use LaravelAgentAccess\Adapters\Laravel\SpatieRoleResolver;

'role_resolver' => SpatieRoleResolver::class,
'roles' => ['spatie_guard' => 'web'],
```

Use the guard of your existing Spatie role catalog, which need not share the name of the HTTP authentication guard. Leave `spatie_guard` as `null` to use the model's Spatie guard. A role belonging to another guard cannot approve a request. Laravel Agent Access does not create roles or attach them to users; the Workbench seeder creates its own demonstration IAM.

## Host actions and conditions

Register Action subclasses and complete GrantRule objects in `config/agent-access.php`. See the complete [refund handler](../workbench/app/Actions/RefundOrder.php), [Policy](../workbench/app/Policies/OrderPolicy.php) and [condition](../workbench/app/Conditions/SameDepartment.php).

Every action supplies validation, normalization and a server-side resource resolver. Bind all scalar arguments that the approver must authorize, such as `amount_minor` and `currency`. The execution handler receives the normalized arguments used by its final admission decision. PHP conditions must tolerate that approval restores only the resource ID and bound arguments; unbound input is not persisted. Conditions must not depend on omitted arguments.

`GrantCondition::requestable()` checks actor-independent eligibility both during request creation and grant use. `allows()` additionally checks the concrete approver, e.g. a shared department. Put a revocable recipient eligibility restriction in `requestable()` if it must also block already issued grants. Losing a grantor role blocks future approvals/management; it does not itself revoke a previously issued grant.

Explicit limits exceeding a rule are rejected. Omitting PHP `maxUses` uses `grants.default_uses`, exactly like HTTP/MCP/Artisan. `maxUses: null` explicitly requests unlimited uses and requires `unlimitedUses()` in the selected rule. Changing a default does not silently shrink it to a rule's limit. An incompatible default requires an explicit smaller request or a configuration correction.

Refreshes of Eloquent principals occur before IAM decisions. Your IAM adapter remains responsible for invalidating any additional permission cache it owns. The handler must check business invariants at the write and apply downstream idempotency. A one-use grant pays for one attempt even when the handler fails.

## HTTP with Sanctum

In a host that already authenticates users with Sanctum:

```php
'http' => [
    'enabled' => true,
    'prefix' => 'agent-access/v1',
    'middleware' => ['auth:sanctum', 'throttle:agent-access'],
],
```

The host issues user tokens and may add middleware enforcing token abilities. Requests supply `action` and `arguments`; no principal or user ID is accepted as authentication. Support and manager use separate authenticated sessions. See the [HTTP walkthrough](demo.md).

## MCP with Sanctum

Install `laravel/mcp:^1.0.1` in the host and register a Server containing the workflow tools from `LaravelAgentAccess\Adapters\Mcp\Tools`. The same registry includes optional discovery, simulation and execution-receipt tools. Use the [OrdersServer](../workbench/app/Mcp/OrdersServer.php) as a complete registry example.

Business tools extend `AgentAccessTool`, set the registered `$action`, and define an input schema. The [RefundTool](../workbench/app/Mcp/RefundTool.php) delegates to the shared executor through the final base `handle()` method. Tool descriptions and discoverability do not authorize execution.

In the host's `routes/ai.php`:

```php
use App\Mcp\OrdersServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', OrdersServer::class)
    ->middleware(['auth:sanctum', 'throttle:agent-access']);
```

Configure the client with the URL and the user's bearer token. Laravel MCP provides the transport; Laravel Agent Access provides authorization and workflow. A denied tool returns `isError`, structured content and a text fallback. After approval the agent explicitly retries the business tool.

## MCP with existing Passport

Reuse the host's Passport user guard rather than creating an OAuth server in this package. For an already issued user access token, protect the route with that guard (commonly `auth:api`):

```php
Mcp::web('/mcp', OrdersServer::class)
    ->middleware(['auth:api', 'throttle:agent-access']);
```

For clients using OAuth discovery, register `Mcp::oauthRoutes()` once in the host and use its existing Passport authorization/consent flow. Laravel MCP 1.0.1 advertises `mcp:use`; application action permissions remain IAM decisions. Configure additional scope middleware according to the host's installed Passport version. This guide's registration methods are checked against the [stable MCP 1.0.1 registrar](https://github.com/laravel/mcp/blob/v1.0.1/src/Server/Registrar.php) and [Laravel MCP authentication documentation](https://laravel.com/docs/13.x/mcp#authentication).

The authenticated guard must return the configured human user model. Client-credentials authentication without a user is outside V0.1. Existing Passport keys, clients, token lifetimes, consent and token revocation remain host responsibilities. The Workbench demo uses Sanctum. A separate disposable Passport host verifies discovery, authorization-code consent, S256 PKCE, actual user tokens, HTTP/MCP approval and refund, identity override rejection and token revocation; see [Passport verification](passport-verification.md).

The optional HTTP workflow can also use `auth:api` by replacing its configured middleware. Authentication guard names and Spatie role guard names are separate configuration choices.

## Validate and cache the host configuration

```sh
php artisan agent-access:install
# Configure class-based resolvers, actions, rules and Policies.
php artisan agent-access:validate
php artisan migrate
php artisan config:cache
php artisan route:cache
```

Installation preserves existing files unless `--force` is explicit. The installer never executes migrations. Validation runs the same validator as package boot. Use `version()` on a GrantRule when external condition configuration changes its meaning; changed fingerprints block old requests and grants.

Run PHP access operations outside an outer transaction so admission can commit before the handler. Artisan administration requires `--as=<user-id>` and the same policies; this local identity selection is not exposed to HTTP or MCP.
