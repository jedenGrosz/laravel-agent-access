<?php

declare(strict_types=1);

namespace Workbench\App\Conditions;

use LaravelAgentAccess\Contracts\GrantCondition;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Rules\GrantConstraints;
use Workbench\App\Models\User;

final class SameDepartment implements GrantCondition
{
    public function requestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): bool
    {
        return $target->user instanceof User && ! $target->user->is_suspended;
    }

    public function allows(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): bool
    {
        return $actor->user instanceof User && $target->user instanceof User && ! $actor->user->is_suspended && $actor->user->department === $target->user->department;
    }
}
