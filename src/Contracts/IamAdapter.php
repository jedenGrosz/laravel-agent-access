<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;

interface IamAdapter
{
    public function evaluate(Principal $principal, AccessRequirement $requirement): IamDecision;
}
