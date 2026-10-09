<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Rules\ConditionExplanation;
use LaravelAgentAccess\Rules\GrantConstraints;

interface ExplainedGrantCondition extends GrantCondition
{
    /** Called only after a failed eligibility check. Return public codes/messages, never payloads or exception details. */
    public function explainRequestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): ConditionExplanation;

    /** Called only after a failed actor condition. */
    public function explain(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints): ConditionExplanation;
}
