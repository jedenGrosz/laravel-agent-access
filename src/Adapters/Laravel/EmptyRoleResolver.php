<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Laravel;

use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\Principal;

final class EmptyRoleResolver implements RoleResolver
{
    public function roles(Principal $principal): array
    {
        return [];
    }
}
