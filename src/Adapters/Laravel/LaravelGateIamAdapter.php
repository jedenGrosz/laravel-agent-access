<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Authorization\IamOutcome;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;

final readonly class LaravelGateIamAdapter implements IamAdapter
{
    public function __construct(private Gate $gate) {}

    public function evaluate(Principal $principal, AccessRequirement $requirement): IamDecision
    {
        $principal->refresh();
        $response = $this->gate->forUser($principal->user)->inspect($requirement->ability,
            $requirement->resource === null ? [] : [$requirement->resource, $requirement->arguments]);

        return new IamDecision($response->allowed() ? IamOutcome::Allow :
            ($response->code() === IamDecision::MISSING_PERMISSION_CODE ? IamOutcome::MissingPermission : IamOutcome::Forbidden));
    }
}
