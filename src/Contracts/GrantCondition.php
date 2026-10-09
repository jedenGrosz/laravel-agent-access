<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Rules\GrantConstraints;

interface GrantCondition
{
    /** Actor-independent eligibility; do not promise a specific approver exists. */
    public function requestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): bool;

    public function allows(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): bool;
}
