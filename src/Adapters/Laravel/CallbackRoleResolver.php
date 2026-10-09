<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Laravel;

use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\Principal;

final readonly class CallbackRoleResolver implements RoleResolver
{
    /**
     * @param \Closure(Principal): list<string> $callback */
    public function __construct(private \Closure $callback) {}

    public function roles(Principal $principal): array
    {
        $principal->refresh();

        return ($this->callback)($principal);
    }
}
