<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Authorization;

use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequirementResolver;
use LaravelAgentAccess\Rules\GrantConstraints;
use LaravelAgentAccess\Rules\GrantPolicy;
use LaravelAgentAccess\Rules\RuleRegistry;

final readonly class DecisionService
{
    public function __construct(private IamAdapter $iam, private GrantService $grants, private RuleRegistry $rules,
        private GrantPolicy $policy, private RequirementResolver $requirements, private ActionRegistry $actions) {}

    /**
     * @param array<string, mixed> $arguments */
    public function check(ExecutionContext $context, string $action, array $arguments): AccessDecision
    {
        $decision = $this->evaluate($context, $this->requirements->resolve($action, $arguments));
        if (! $decision->allowed) {
            app(AuditWriter::class)->record('ACCESS_CHECK_DENIED', $context, ['reason_code' => $decision->reasonCode]);
        }

        return $decision;
    }

    public function evaluate(ExecutionContext $context, AccessRequirement $requirement): AccessDecision
    {
        $context->principal->refresh();
        $baseline = $this->iam->evaluate($context->principal, $requirement);
        if ($baseline->outcome === IamOutcome::Allow) {
            return new AccessDecision(true, 'BASE_PERMISSION', $requirement, $context->correlationId, 'iam');
        }
        if ($baseline->outcome === IamOutcome::Forbidden) {
            return new AccessDecision(false, 'FORBIDDEN', $requirement, $context->correlationId);
        }
        $grant = $this->grants->find($context, $requirement);
        if ($grant !== null) {
            return new AccessDecision(true, 'VALID_GRANT', $requirement, $context->correlationId, 'grant', $grant->id);
        }
        $options = [];
        foreach ($this->rules->all() as $rule) {
            if (array_diff($rule->boundArguments, $this->actions->get($requirement->action)->bindableArguments()) !== []) {
                continue;
            }
            $defaults = new GrantConstraints(min((int) config('agent-access.grants.default_ttl', 900), $rule->maximumDuration,
                (int) config('agent-access.grants.max_ttl', 86400)), $rule->maximumUses === null
                    ? (int) config('agent-access.grants.default_uses', 1)
                    : min((int) config('agent-access.grants.default_uses', 1), $rule->maximumUses));
            if ($this->policy->requestable($context->principal, $requirement, $defaults, $rule)) {
                $options[] = ['rule_id' => $rule->id, 'eligible_grantor_roles' => $rule->grantorRoles,
                    'max_duration_seconds' => min($rule->maximumDuration, (int) config('agent-access.grants.max_ttl', 86400)),
                    'max_uses' => $rule->maximumUses, 'resource_required' => $rule->resourceRequired, 'bound_argument_names' => $rule->boundArguments];
            }
        }

        return new AccessDecision(false, 'MISSING_PERMISSION', $requirement, $context->correlationId, grantOptions: $options);
    }
}
