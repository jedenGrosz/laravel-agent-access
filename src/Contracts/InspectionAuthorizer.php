<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use LaravelAgentAccess\Identity\ExecutionContext;

interface InspectionAuthorizer
{
    public function allows(ExecutionContext $context, string $targetId): bool;
}
