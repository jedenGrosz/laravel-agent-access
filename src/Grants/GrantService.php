<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Grants;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequirementResolver;
use LaravelAgentAccess\Rules\GrantConstraints;
use LaravelAgentAccess\Rules\GrantPolicy;
use LaravelAgentAccess\Rules\RuleRegistry;

final readonly class GrantService
{
    public function __construct(private RuleRegistry $rules, private GrantPolicy $policy, private RequirementResolver $requirements,
        private PrincipalDirectory $principals, private AuditWriter $audit) {}

    public function find(ExecutionContext $context, AccessRequirement $requirement): ?Grant
    {
        $candidates = Grant::query()->where('principal_type', $context->principal->type)->where('principal_id', $context->principal->id)
            ->where('action', $requirement->action)->where('permission', $requirement->permission)->whereNull('revoked_at')
            ->where('valid_from', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))->where('expires_at', '>', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))
            ->orderBy('expires_at')->orderBy('id')->get();
        foreach ($candidates as $grant) {
            if ($this->matches($grant, $context, $requirement)) {
                return $grant;
            }
        }

        return null;
    }

    public function matches(Grant $grant, ExecutionContext $context, AccessRequirement $requirement): bool
    {
        $now = CarbonImmutable::now('UTC');
        $rule = $this->rules->get($grant->rule_id);
        if ($rule === null || ! hash_equals($rule->fingerprint(), $grant->rule_fingerprint) ||
            $grant->principal_type !== $context->principal->type || $grant->principal_id !== $context->principal->id ||
            $grant->action !== $requirement->action || $grant->permission !== $requirement->permission ||
            $grant->resource_type !== $requirement->resourceType || $grant->resource_id !== $requirement->resourceId ||
            $grant->revoked_at !== null || $grant->valid_from->greaterThan($now) || $grant->expires_at->lessThanOrEqualTo($now) ||
            ($grant->max_uses !== null && $grant->uses >= $grant->max_uses)) {
            return false;
        }
        foreach ($grant->bound_arguments as $key => $value) {
            if (! array_key_exists($key, $requirement->arguments) || $requirement->arguments[$key] !== $value) {
                return false;
            }
        }

        return $this->policy->requestable($context->principal, $requirement, new GrantConstraints($grant->duration_seconds, $grant->max_uses), $rule);
    }

    public function canManage(ExecutionContext $context, Grant $grant): bool
    {
        if ($grant->principal_type === $context->principal->type && $grant->principal_id === $context->principal->id) {
            return true;
        }
        $rule = $this->rules->get($grant->rule_id);
        if ($rule === null || ! hash_equals($rule->fingerprint(), $grant->rule_fingerprint)) {
            return false;
        }
        try {
            return $this->policy->allows($context->principal, $this->principals->find($grant->principal_id, $grant->principal_type),
                $this->requirements->restore($grant->action, $grant->resource_id, $grant->bound_arguments),
                new GrantConstraints($grant->duration_seconds, $grant->max_uses), $rule);
        } catch (AccessException|ModelNotFoundException) {
            return false;
        }
    }

    public function revoke(ExecutionContext $context, string $id): Grant
    {
        return DB::transaction(function () use ($context, $id): Grant {
            $grant = Grant::query()->lockForUpdate()->find($id);
            if ($grant === null || ! $this->canManage($context, $grant)) {
                throw new AccessException('GRANT_NOT_FOUND', 404);
            }
            if ($grant->revoked_at === null) {
                $grant->fill(['revoked_at' => CarbonImmutable::now('UTC'), 'revoked_by_type' => $context->principal->type, 'revoked_by_id' => $context->principal->id])->save();
                $this->audit->record('GRANT_REVOKED', $context, ['grant_id' => $grant->id, 'request_id' => $grant->request_id]);
            }

            return $grant;
        });
    }
}
