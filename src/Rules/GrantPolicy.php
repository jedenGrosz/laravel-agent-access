<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

use Illuminate\Contracts\Container\Container;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;

final readonly class GrantPolicy
{
    public function __construct(private RoleResolver $roles, private Container $container) {}

    public function requestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints, GrantRule $rule): bool
    {
        return $this->inspectRequestable($target, $requirement, $constraints, $rule)->allowed;
    }

    public function inspectRequestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints, GrantRule $rule): GrantPolicyDecision
    {
        $target->refresh();
        $checks = [];
        foreach ([
            ['permission', 'PERMISSION_MISMATCH', fn (): bool => $rule->permission === $requirement->permission],
            ['recipient_role', 'RECIPIENT_ROLE_REQUIRED', fn (): bool => (bool) array_intersect($rule->targetRoles, $this->roles->roles($target))],
            ['duration', 'DURATION_EXCEEDED', fn (): bool => $constraints->durationSeconds <= min($rule->maximumDuration, (int) config('agent-access.grants.max_ttl', 86400))],
            ['resource', 'RESOURCE_REQUIRED', fn (): bool => ! $rule->resourceRequired || $requirement->resourceId !== null],
            ['uses', 'USES_EXCEEDED', fn (): bool => $rule->maximumUses === null || ($constraints->maxUses !== null && $constraints->maxUses <= $rule->maximumUses)],
        ] as [$name, $code, $test]) {
            $allowed = $test();
            $checks[] = ['check' => $name, 'allowed' => $allowed, 'reason_code' => $allowed ? 'SATISFIED' : $code];
            if (! $allowed) {
                return new GrantPolicyDecision(false, $code, $checks);
            }
        }
        foreach ($rule->boundArguments as $name) {
            $allowed = array_key_exists($name, $requirement->arguments) && ! is_float($requirement->arguments[$name]) &&
                (is_scalar($requirement->arguments[$name]) || $requirement->arguments[$name] === null);
            $checks[] = ['check' => 'argument:'.$name, 'allowed' => $allowed, 'reason_code' => $allowed ? 'SATISFIED' : 'ARGUMENT_BINDING_INVALID'];
            if (! $allowed) {
                return new GrantPolicyDecision(false, 'ARGUMENT_BINDING_INVALID', $checks);
            }
        }
        foreach ($rule->conditions as $index => $class) {
            $allowed = $this->container->make($class)->requestable($target, $requirement, $constraints);
            $checks[] = ['check' => 'recipient_condition:'.($index + 1), 'allowed' => $allowed, 'reason_code' => $allowed ? 'SATISFIED' : 'CONDITION_NOT_MET'];
            if (! $allowed) {
                return new GrantPolicyDecision(false, 'CONDITION_NOT_MET', $checks);
            }
        }

        return new GrantPolicyDecision(true, 'REQUEST_ELIGIBLE', $checks);
    }

    public function allows(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints, GrantRule $rule): bool
    {
        return $this->inspect($actor, $target, $requirement, $constraints, $rule)->allowed;
    }

    public function inspect(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints, GrantRule $rule): GrantPolicyDecision
    {
        $actor->refresh();
        if ($actor->equals($target)) {
            return new GrantPolicyDecision(false, 'SELF_APPROVAL_FORBIDDEN', [['check' => 'distinct_actor', 'allowed' => false, 'reason_code' => 'SELF_APPROVAL_FORBIDDEN']]);
        }
        $eligibility = $this->inspectRequestable($target, $requirement, $constraints, $rule);
        if (! $eligibility->allowed) {
            return $eligibility;
        }
        $checks = [['check' => 'distinct_actor', 'allowed' => true, 'reason_code' => 'SATISFIED'], ...$eligibility->checks];
        $allowed = (bool) array_intersect($rule->grantorRoles, $this->roles->roles($actor));
        $checks[] = ['check' => 'grantor_role', 'allowed' => $allowed, 'reason_code' => $allowed ? 'SATISFIED' : 'GRANTOR_ROLE_REQUIRED'];
        if (! $allowed) {
            return new GrantPolicyDecision(false, 'GRANTOR_ROLE_REQUIRED', $checks);
        }
        foreach ($rule->conditions as $index => $class) {
            $allowed = $this->container->make($class)->allows($actor, $target, $requirement, $constraints);
            $checks[] = ['check' => 'grantor_condition:'.($index + 1), 'allowed' => $allowed, 'reason_code' => $allowed ? 'SATISFIED' : 'CONDITION_NOT_MET'];
            if (! $allowed) {
                return new GrantPolicyDecision(false, 'CONDITION_NOT_MET', $checks);
            }
        }

        return new GrantPolicyDecision(true, 'GRANT_ALLOWED', $checks);
    }
}
