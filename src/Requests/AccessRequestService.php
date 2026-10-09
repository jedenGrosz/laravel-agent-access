<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Requirements\RequirementResolver;
use LaravelAgentAccess\Rules\GrantConstraints;
use LaravelAgentAccess\Rules\GrantPolicy;
use LaravelAgentAccess\Rules\GrantUses;
use LaravelAgentAccess\Rules\RuleRegistry;
use LaravelAgentAccess\Support\CanonicalJson;

final readonly class AccessRequestService
{
    public function __construct(private DecisionService $decisions, private RuleRegistry $rules, private GrantPolicy $policy,
        private RequirementResolver $requirements, private ActionRegistry $actions, private PrincipalDirectory $principals, private AuditWriter $audit) {}

    /**
     * @param array<string, mixed> $arguments */
    public function create(ExecutionContext $context, string $action, array $arguments, ?string $ruleId = null,
        ?int $durationSeconds = null, int|GrantUses|null $maxUses = GrantUses::Default, ?string $reason = null, ?string $idempotencyKey = null): AccessRequest
    {
        if (($reason !== null && mb_strlen($reason) > 500) || ($idempotencyKey !== null && ($idempotencyKey === '' || strlen($idempotencyKey) > 128))) {
            throw new AccessException('INVALID_REQUEST', 422);
        }
        $requirement = $this->requirements->resolve($action, $arguments);
        $maxUses = $maxUses === GrantUses::Default ? (int) config('agent-access.grants.default_uses', 1) : $maxUses;
        $hashArgs = $requirement->arguments;
        ksort($hashArgs);
        $hash = hash('sha256', CanonicalJson::encode([$action, $hashArgs, $ruleId, $durationSeconds, $maxUses, $reason]));
        if ($idempotencyKey !== null && ($existing = $this->replay($context, $idempotencyKey, $hash)) !== null) {
            return $existing;
        }
        $decision = $this->decisions->evaluate($context, $requirement);
        if ($decision->allowed) {
            throw new AccessException('ACCESS_ALREADY_AVAILABLE', 409);
        }
        if ($decision->reasonCode === 'FORBIDDEN') {
            throw new AccessException('ACCESS_DENIED', 403, $decision);
        }
        if ($ruleId === null) {
            if (count($decision->grantOptions) !== 1) {
                throw new AccessException('SELECT_GRANT_RULE', 422, $decision);
            }
            $ruleId = (string) $decision->grantOptions[0]['rule_id'];
        }
        $rule = $this->rules->get($ruleId);
        if ($rule === null || array_diff($rule->boundArguments, $this->actions->get($action)->bindableArguments()) !== []) {
            throw new AccessException('INVALID_GRANT_RULE', 422);
        }
        $durationSeconds ??= min((int) config('agent-access.grants.default_ttl', 900), $rule->maximumDuration, (int) config('agent-access.grants.max_ttl', 86400));
        try {
            $constraints = new GrantConstraints($durationSeconds, $maxUses);
        } catch (\InvalidArgumentException) {
            throw new AccessException('INVALID_CONSTRAINTS', 422);
        }
        if (! $this->policy->requestable($context->principal, $requirement, $constraints, $rule)) {
            throw new AccessException('INVALID_CONSTRAINTS', 422);
        }
        $bound = array_intersect_key($requirement->arguments, array_flip($rule->boundArguments));
        try {
            return DB::transaction(function () use ($context, $requirement, $constraints, $rule, $reason, $idempotencyKey, $hash, $bound): AccessRequest {
                $request = AccessRequest::query()->create(['principal_type' => $context->principal->type, 'principal_id' => $context->principal->id,
                    'action' => $requirement->action, 'permission' => $requirement->permission, 'resource_type' => $requirement->resourceType,
                    'resource_id' => $requirement->resourceId, 'bound_arguments' => $bound, 'duration_seconds' => $constraints->durationSeconds,
                    'max_uses' => $constraints->maxUses, 'rule_id' => $rule->id, 'rule_fingerprint' => $rule->fingerprint(), 'status' => 'pending',
                    'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'payload_hash' => $hash,
                    'expires_at' => CarbonImmutable::now('UTC')->addSeconds((int) config('agent-access.requests.ttl', 3600))]);
                $this->audit->record('ACCESS_REQUEST_CREATED', $context, ['request_id' => $request->id, 'rule_id' => $rule->id]);

                return $request;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null && ($existing = $this->replay($context, $idempotencyKey, $hash)) !== null) {
                return $existing;
            }
            throw $exception;
        }
    }

    private function replay(ExecutionContext $context, string $key, string $hash): ?AccessRequest
    {
        $existing = AccessRequest::query()->where('principal_type', $context->principal->type)->where('principal_id', $context->principal->id)->where('idempotency_key', $key)->first();
        if ($existing !== null && ! $this->owns($context, $existing)) {
            throw new AccessException('IDEMPOTENCY_CONFLICT', 409);
        }
        if ($existing !== null && ! hash_equals($existing->payload_hash, $hash)) {
            throw new AccessException('IDEMPOTENCY_CONFLICT', 409);
        }

        return $existing;
    }

    public function canApprove(ExecutionContext $context, AccessRequest $request): bool
    {
        $rule = $this->rules->get($request->rule_id);
        if ($rule === null || ! hash_equals($rule->fingerprint(), $request->rule_fingerprint)) {
            return false;
        }
        try {
            return $this->policy->allows($context->principal, $this->principals->find($request->principal_id, $request->principal_type),
                $this->requirements->restore($request->action, $request->resource_id, $request->bound_arguments),
                new GrantConstraints($request->duration_seconds, $request->max_uses), $rule);
        } catch (AccessException|ModelNotFoundException) {
            return false;
        }
    }

    public function owns(ExecutionContext $context, AccessRequest $request): bool
    {
        return $request->principal_type === $context->principal->type && $request->principal_id === $context->principal->id;
    }

    public function show(ExecutionContext $context, string $id): AccessRequest
    {
        $request = AccessRequest::query()->find($id);
        if ($request === null || (! $this->owns($context, $request) && ! $this->canApprove($context, $request))) {
            throw new AccessException('REQUEST_NOT_FOUND', 404);
        }
        if ($request->status === 'pending' && $request->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $this->expire($context, $request->id);
            $request->refresh();
        }

        return $request;
    }

    public function cancel(ExecutionContext $context, string $id): AccessRequest
    {
        return DB::transaction(function () use ($context, $id): AccessRequest {
            $request = AccessRequest::query()->lockForUpdate()->find($id);
            if ($request === null || ! $this->owns($context, $request)) {
                throw new AccessException('REQUEST_NOT_FOUND', 404);
            }
            if ($request->status === 'cancelled') {
                return $request;
            }
            if ($request->status !== 'pending') {
                throw new AccessException('REQUEST_STATE_CONFLICT', 409);
            }
            if ($request->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                $this->markExpired($context, $request);

                return $request;
            }
            $request->fill(['status' => 'cancelled', 'decided_at' => CarbonImmutable::now('UTC')])->save();
            $this->audit->record('ACCESS_REQUEST_CANCELLED', $context, ['request_id' => $request->id]);

            return $request;
        });
    }

    public function expire(ExecutionContext $context, string $id): void
    {
        DB::transaction(function () use ($context, $id): void {
            $request = AccessRequest::query()->lockForUpdate()->find($id);
            if ($request !== null && $request->status === 'pending' && $request->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                $this->markExpired($context, $request);
            }
        });
    }

    private function markExpired(ExecutionContext $context, AccessRequest $request): void
    {
        $request->fill(['status' => 'expired', 'decided_at' => CarbonImmutable::now('UTC')])->save();
        $this->audit->record('ACCESS_REQUEST_EXPIRED', $context, ['request_id' => $request->id]);
    }
}
