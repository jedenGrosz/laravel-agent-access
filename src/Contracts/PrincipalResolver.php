<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use LaravelAgentAccess\Identity\Principal;

interface PrincipalResolver
{
    public function resolve(?Authenticatable $user = null): Principal;
}
