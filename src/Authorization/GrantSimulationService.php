<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Authorization;

use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Contracts\InspectionAuthorizer;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Requirements\RequirementResolver;
use LaravelAgentAccess\Rules\GrantConstraints;
use LaravelAgentAccess\Rules\GrantPolicy;
use LaravelAgentAccess\Rules\GrantUses;
use LaravelAgentAccess\Rules\RoleHierarchy;
use LaravelAgentAccess\Rules\RuleRegistry;

final readonly class GrantSimulationService
{
    public function __construct(private InspectionAuthorizer $inspection, private PrincipalDirectory $principals,
        private RequirementResolver $requirements, private ActionRegistry $actions, private RuleRegistry $rules,
        private GrantPolicy $policy, private IamAdapter $iam, private RoleResolver $roles, private RoleHierarchy $hierarchy) {}

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    public function simulate(ExecutionContext $context, string $action, array $arguments, string $ruleId,
        ?string $targetId = null, ?int $durationSeconds = null, int|GrantUses|null $maxUses = GrantUses::Default): array
    {
        $targetId ??= $context->principal->id;
        // Check inspection authority before looking up another user or resolving a resource.
        if ($targetId !== $context->principal->id && ! $this->inspection->allows($context, $targetId)) {
            throw new AccessException('SIMULATION_NOT_FOUND', 404);
        }
        $target = $targetId === $context->principal->id ? $context->principal : $this->principals->find($targetId);
        $target->refresh();
        $requirement = $this->requirements->resolve($action, $arguments);
        $rule = $this->rules->get($ruleId);
        if ($rule === null || $rule->permission !== $requirement->permission ||
            array_diff($rule->boundArguments, $this->actions->get($action)->bindableArguments()) !== []) {
            throw new AccessException('INVALID_GRANT_RULE', 422);
        }
        $durationSeconds ??= min((int) config('agent-access.grants.default_ttl', 900), $rule->maximumDuration, (int) config('agent-access.grants.max_ttl', 86400));
        $maxUses = $maxUses === GrantUses::Default ? (int) config('agent-access.grants.default_uses', 1) : $maxUses;
        try {
            $constraints = new GrantConstraints($durationSeconds, $maxUses);
        } catch (\InvalidArgumentException) {
            throw new AccessException('INVALID_CONSTRAINTS', 422);
        }
        $baseline = $this->iam->evaluate($target, $requirement);
        $recipient = $this->policy->inspectRequestable($target, $requirement, $constraints, $rule);
        $grant = $this->policy->inspect($context->principal, $target, $requirement, $constraints, $rule);
        $allowed = $baseline->outcome === IamOutcome::MissingPermission && $grant->allowed;
        $code = match ($baseline->outcome) {
            IamOutcome::Allow => 'ACCESS_ALREADY_AVAILABLE',
            IamOutcome::Forbidden => 'FORBIDDEN',
            IamOutcome::MissingPermission => $grant->reasonCode,
        };

        return ['schema_version' => 1, 'code' => 'GRANT_SIMULATION', 'allowed' => $allowed, 'reason_code' => $code,
            'reason' => match ($baseline->outcome) {
                IamOutcome::Allow => 'The recipient already has baseline access; no grant is needed.',
                IamOutcome::Forbidden => 'The existing IAM forbids this action; the rule cannot override it.',
                IamOutcome::MissingPermission => $grant->reason(),
            },
            'baseline' => $baseline->outcome->value, 'rule_id' => $rule->id, 'requirement' => $requirement->toArray(),
            'constraints' => ['duration_seconds' => $durationSeconds, 'max_uses' => $maxUses],
            'request_eligibility' => $recipient->toArray(), 'grant_authority' => $grant->toArray(),
            'grantor_hierarchy' => $this->hierarchy->rankGrantors($rule->grantorRoles, $this->roles->roles($target)),
            'correlation_id' => $context->correlationId];
    }
}
