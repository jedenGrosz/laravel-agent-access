# Hierarchy and grant simulation

The hierarchy is a directed acyclic graph of parent-to-child role relations. It helps navigate and order potential grantors. A complete GrantRule is still the sole source of grant authority; a role outside the graph remains eligible when the rule allows it.

## Graph navigation

`RoleHierarchy` exposes `roles()`, `roots()`, `ancestors(role)`, `descendants(role)`, `shortestPath(parent, child)` and `distance(parent, child)`. Paths follow parent-to-child edges. Unknown or unrelated roles have no path; a known role's distance to itself is zero. Ancestors/descendants are unique and ordered by breadth-first traversal with alphabetical ties. Multiple parents are supported.

Each requestable grant option now contains `grantor_hierarchy`, an ordered list of role, distance and path. The distance is the shortest path to any of the recipient's roles. Unrelated roles are ordered after related roles and retain null path/distance. This does not claim that a specific approver exists or is available.

```sh
php artisan agent-access:hierarchy
php artisan agent-access:hierarchy --json
```

The human presentation marks repeated nodes as `[shared]`; JSON preserves the configured edges.

## Explain a rule using the actual policy

`GrantPolicy::inspectRequestable()` and `inspect()` return `GrantPolicyDecision`. Existing `requestable()` and `allows()` delegate to those same methods. Explanations therefore follow the evaluation used by real request creation and approval, with short-circuiting at the first failure.

Checks cover permission, recipient role, duration, resource scope, uses, bound arguments, distinct actor, grantor role and business conditions. Condition identifiers are positional (`recipient_condition:1`, `grantor_condition:1`) rather than PHP class names. No argument values or user records are included in the trace. Conditions and IAM adapters must be read-only checks; exceptions fail closed.

Public access decisions also include a human-readable `reason` alongside their stable reason code. Existing decision fields remain available.

## Simulate without issuing access

PHP uses `GrantSimulationService::simulate(context, action, arguments, ruleId, targetId, durationSeconds, maxUses)`. The actor always comes from the context; `targetId` identifies the proposed recipient. The selected rule is evaluated independently and exact proposed limits are retained.

The result reports baseline IAM, recipient eligibility, grant authority, the evaluated checks and hierarchy hints. `allowed` is true only for `MISSING_PERMISSION` plus a successful complete grant rule. Baseline `FORBIDDEN` always blocks issuance; baseline access reports that no grant is needed. An existing grant is not consumed and does not substitute for baseline IAM or grant authority in this simulation.

The package writes no request, approval, grant, use or audit event during simulation. It resolves current users and resources and invokes the host's IAM/conditions. A positive preview is not a future approval token; real approval reevaluates everything.

## Control visibility separately

Simulation for the authenticated user's own identity is permitted and still reports that self approval is forbidden. Simulation for another recipient first requires explicit authorization through `InspectionAuthorizer`. Its default implementation calls raw host Gate ability `agent_access.inspect`, with the target ID as an argument. Business grants never supplement this check. With no defined ability, cross-user inspection is denied before the target/resource is resolved.

The host must include any tenant, organization or department visibility restrictions in that ability, or bind its own `InspectionAuthorizer`. The Orders demo explicitly lets active finance managers inspect recipients in their department. Grantor status by itself does not automatically confer diagnostic visibility in a host.

```php
Gate::define('agent_access.inspect', function (User $actor, string $recipientId): bool {
    return $actor->can('access-diagnostics.view')
        && User::query()->whereKey($recipientId)->where('organization_id', $actor->organization_id)->exists();
});
```

Configure a different ability in `agent-access.inspection.ability` if needed. Denied cross-user inspection returns 404. Inspection authority allows a preview, not approval or execution.

## Shared transports

- HTTP: `POST /agent-access/v1/simulate`.
- MCP: register `LaravelAgentAccess\Adapters\Mcp\Tools\SimulateGrant` (`access.simulate`).
- Artisan: `agent-access:simulate --as=<actor-id> --input=<json>`.

Input:

```json
{
  "action": "orders.refund",
  "arguments": {"order_id": 431, "amount_minor": 10000, "currency": "PLN"},
  "rule_id": "finance-refund-support",
  "recipient_id": "1",
  "duration_seconds": 900,
  "max_uses": 1
}
```

Omit `recipient_id` to use the authenticated identity. It never overrides the actor. `user_id`, `principal`, `actor_id`, `target_user_id`, `target` and client roles remain prohibited in transport input. The same inspection policy also applies to Artisan despite its local `--as` option.
