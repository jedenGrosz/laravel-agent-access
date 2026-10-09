<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Actions;

use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\RequirementResolver;
use LaravelAgentAccess\Rules\RoleHierarchy;
use LaravelAgentAccess\Rules\RuleRegistry;

final readonly class ActionCatalog
{
    public function __construct(private ActionRegistry $actions, private RequirementResolver $requirements,
        private RuleRegistry $rules, private RoleResolver $roles, private RoleHierarchy $hierarchy) {}

    /** Metadata only: no resource resolution, IAM check, grant consumption or handler execution.
     * @return array<string, mixed> */
    public function list(ExecutionContext $context): array
    {
        $context->principal->refresh();
        $roles = $this->roles->roles($context->principal);
        $entries = [];
        foreach ($this->actions->all() as $action) {
            $requirement = $this->requirements->attribute($action->key());
            $candidates = [];
            foreach ($this->rules->all() as $rule) {
                if ($rule->permission !== $requirement->permission || ! array_intersect($roles, $rule->targetRoles)) {
                    continue;
                }
                $candidates[] = ['rule_id' => $rule->id, 'max_duration_seconds' => min($rule->maximumDuration, (int) config('agent-access.grants.max_ttl', 86400)),
                    'max_uses' => $rule->maximumUses, 'resource_required' => $rule->resourceRequired,
                    'bound_argument_names' => $rule->boundArguments, 'grantor_hierarchy' => $this->hierarchy->rankGrantors($rule->grantorRoles, $roles)];
            }
            $entries[] = ['action' => $action->key(), 'description' => $action->description(), 'permission' => $requirement->permission,
                'resource_type' => $requirement->resourceType, 'resource_argument' => $requirement->resourceArgument,
                'argument_names' => array_keys($action->rules()), 'bindable_argument_names' => $action->bindableArguments(),
                'candidate_grant_rules' => $candidates];
        }
        usort($entries, fn (array $a, array $b): int => strcmp($a['action'], $b['action']));

        return ['schema_version' => 1, 'code' => 'ACTION_CATALOG', 'data' => $entries, 'correlation_id' => $context->correlationId];
    }
}
