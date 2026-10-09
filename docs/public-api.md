# V0.1 public integration contracts

The package extends the host's IAM. Integrations supply existing users, roles, Gate/Policies, registered Action classes and complete GrantRule definitions. The services below are the supported path for security mutations; writing package Eloquent models directly bypasses authorization, transaction and audit guarantees.

## Identity and baseline IAM

`Principal` combines an authenticated host user with a stable identity type and string user ID from `getAuthIdentifier()`. Directory lookups use the configured model's `getAuthIdentifierName()`, which may differ from its database primary key. That host column must be unique and stable. HTTP/MCP resolve the actor from Laravel authentication. PHP constructs explicit principals only in trusted server code; Artisan's local administrative `--as` is not a remote authentication mechanism. V0.1 supports one configured Eloquent authenticatable model and identity realm.

`ExecutionContext` records the principal, channel, correlation ID and separate trusted/declared client metadata. Client labels do not affect authority. The default Laravel adapter refreshes the user before evaluating Gate; custom adapters must handle their own additional IAM caches.

| Contract | Method | Host responsibility |
| --- | --- | --- |
| `IamAdapter` | `evaluate(Principal, AccessRequirement): IamDecision` | Return ALLOW, MISSING_PERMISSION or FORBIDDEN; exceptions block execution |
| `RoleResolver` | `roles(Principal): array` | Return existing role names with the correct guard/organization scope |
| `PrincipalResolver` | `resolve(?Authenticatable): Principal` | Accept authenticated host users only |
| `InspectionAuthorizer` | `allows(ExecutionContext, recipientId): bool` | Explicit current authority to inspect another user's simulations/receipts |

Gate permission identifiers and ability names are separate. Ordinary denial is FORBIDDEN; only `Response::deny(..., IamDecision::MISSING_PERMISSION_CODE)` permits escalation. Business grants never supplement management inspection authorization.

## Action inputs and Gate signatures

An Action declares one `RequiresPermission`, a stable key, Laravel validation rules, normalization and a handler. A resource action resolves one existing Eloquent model on the server; the client supplies only its lookup argument. The handler receives the exact normalized arguments used at final admission.

Gate receives these positional arguments after the user:

| Action | Gate/Policy signature |
| --- | --- |
| With resource | `ability(User $user, Order $resource, array $arguments)` |
| Without resource | `ability(User $user, array $arguments)` |

Existing methods may omit unused trailing arguments. For a resource-less action, register an explicit Gate ability, for example:

```php
Gate::define('exportReport', function (User $user, array $arguments): Response {
    if ($user->is_suspended || $arguments['classification'] === 'restricted') {
        return Response::deny('Export is forbidden.');
    }

    return $user->can('reports.export')
        ? Response::allow()
        : Response::deny('Export permission is required.', IamDecision::MISSING_PERMISSION_CODE);
});
```

The same arguments reach Gate during check, request, approval and admission when they are bound. Approval restores only the resource ID and persisted bound arguments; it does not restore arbitrary client input. Bind every scalar value required by the approval Policy/conditions, and ensure resource restoration can work from the stored reference and those bindings. The restored resource lookup ID is a string. Never interpret missing unbound data as additional approval authority.

Exact binding supports strings, integers, booleans and null. Floats, arrays, objects, ranges and expressions are not V0.1 constraints. Normalize money to integer minor units. Normalizers are trusted, deterministic host code; their output is validated again. Discovery exposes public field names rather than raw validation expressions.

## Rules, conditions and revisions

Each request selects one complete GrantRule. Its permission, recipient/grantor roles, resource requirement, TTL, uses and bound arguments all apply together. The hierarchy ranks candidates and never authorizes a grant. Self approval is forbidden.

`GrantCondition::requestable()` checks recipient eligibility during request creation and grant use. `allows()` additionally evaluates the specific approver. These checks must be read-only. Losing the approver's role blocks later approval/management but does not itself revoke an existing grant. Put continuing recipient restrictions in `requestable()`.

`ExplainedGrantCondition` optionally returns safe `ConditionExplanation` data for a failed check. Public messages must not contain raw exceptions, private user data, SQL or secrets. Ordinary boolean conditions remain supported.

Fingerprints include the rule definition and condition source files, including parent classes and recursively used traits. Changes invalidate pending requests and issued grants; unavailable source files fail closed. Fingerprints do not discover arbitrary helper/service/configuration dependencies. Call `version('2')` when such a dependency changes the rule's meaning. Inline/evaluated conditions without a source file also require explicit revision management. Revisions are deployment identifiers, not a policy-editing database.

The candidate's inherited-source fingerprint fix changes fingerprints for rules with conditions compared with RC2. Such old requests/grants become unusable; create a new request after updating the host. No access is broadened and no package table is dropped.

## Shared services and execution

| Service | Public operations |
| --- | --- |
| `DecisionService` | `check(context, action, arguments)` |
| `AccessRequestService` | `create`, `show`, `cancel` |
| `ApprovalService` | `approve`, `reject` |
| `GrantService` | `revoke` |
| `GrantSimulationService` | `simulate` a selected rule without writes |
| `ActionCatalog` | `list` registered action metadata |
| `ActionExecutor` | `execute`, `executeWithReceipt` |
| `ExecutionReceiptService` | `show`, owned `list` |

Public mutation methods never accept `force` or `skipAuthorization`. `check()` is a preview; denied checks are audited and no grant use is consumed. Creation supports requester-scoped idempotency keys; same key/content replays the existing request and changed content conflicts. Explicit oversized constraints are rejected.

Execute outside an existing database transaction. Admission rechecks IAM/resource/grant, consumes one attempt and commits usage plus durable audit before the handler. The handler may open its own transaction and must recheck business invariants at its write. Failure does not restore usage. Approval/revocation and admission locks define concurrency order; admitted work is not interrupted by later revocation.

`execute()` preserves the handler's original array result. `executeWithReceipt()` returns an ExecutionResult with separate execution/correlation IDs and the original result nested under `result`. A business result key named `execution_id` is never overwritten. The HTTP/MCP/Artisan `run` operation uses this envelope.

Receipt reads never run a handler, restore a grant or retry an action. FAILED and OUTCOME_UNKNOWN require review because an exception/crash does not prove that an external effect was rolled back. Receipts omit business payloads/results. External idempotency remains the handler's responsibility.

## Wire responses and errors

Structured responses use `schema_version: 1`. Consumers should branch on stable codes and tolerate additional fields. The check response includes allowed/reason, public requirement, source, requestability, separate grant options and correlation ID. Handler results remain application-defined.

| Situation | HTTP | PHP/structured outcome |
| --- | --- | --- |
| Valid check, including denied decision | 200 | AccessDecision |
| Denied execution | 403 | AccessException plus decision, when available |
| Hidden/unknown record or action | 404 | Safe not-found code |
| Invalid input/constraints | 422 | ValidationException or AccessException |
| State/idempotency conflict | 409 | AccessException |
| IAM/audit/handler infrastructure failure | 500 | Exception; transport emits safe code/correlation |

MCP errors additionally set `isError: true` and include structured content plus a textual JSON fallback. Authentication remains the host's middleware responsibility. HTTP/MCP never accept a caller-selected actor or PHP handler class.

`AuditWriter::record()` must write admission evidence in the same default connection transaction as grant/request changes. A successful admission commit must leave durable audit before the handler starts. The default writer allowlists references/metadata and dispatches AccessEvent after commit. Use queued listeners for downstream work. Custom writers must preserve those transaction and failure semantics; an in-memory logger is insufficient for admission.

See [host integration](integration.md), [verification evidence](verification.md) and [local release preparation](release.md).
