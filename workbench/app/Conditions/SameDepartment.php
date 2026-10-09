<?php

declare(strict_types=1);

namespace Workbench\App\Conditions;

use LaravelAgentAccess\Contracts\ExplainedGrantCondition;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Rules\ConditionExplanation;
use LaravelAgentAccess\Rules\GrantConstraints;
use Workbench\App\Models\User;

final class SameDepartment implements ExplainedGrantCondition
{
    public function requestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): bool
    {
        return $target->user instanceof User && ! $target->user->is_suspended;
    }

    public function allows(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): bool
    {
        return $actor->user instanceof User && $target->user instanceof User && ! $actor->user->is_suspended && $actor->user->department === $target->user->department;
    }

    public function explainRequestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): ConditionExplanation
    {
        return new ConditionExplanation('RECIPIENT_INELIGIBLE', 'An active recipient is required.');
    }

    public function explain(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): ConditionExplanation
    {
        return $actor->user instanceof User && $actor->user->is_suspended
            ? new ConditionExplanation('GRANTOR_INELIGIBLE', 'An active grantor is required.')
            : new ConditionExplanation('DEPARTMENT_MISMATCH', 'The grantor and recipient must belong to the same department.');
    }
}
