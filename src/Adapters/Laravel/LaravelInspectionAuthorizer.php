<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Laravel;

use Illuminate\Support\Facades\Gate;
use LaravelAgentAccess\Contracts\InspectionAuthorizer;
use LaravelAgentAccess\Identity\ExecutionContext;

final class LaravelInspectionAuthorizer implements InspectionAuthorizer
{
    public function allows(ExecutionContext $context, string $targetId): bool
    {
        $context->principal->refresh();

        return Gate::forUser($context->principal->user)->inspect((string) config('agent-access.inspection.ability', 'agent_access.inspect'), [$targetId])->allowed();
    }
}
