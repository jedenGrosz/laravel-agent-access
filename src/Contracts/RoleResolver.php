<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use LaravelAgentAccess\Identity\Principal;

interface RoleResolver
{
    /**
     * @return list<string> */
    public function roles(Principal $principal): array;
}
