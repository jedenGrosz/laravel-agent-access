<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Laravel;

use Illuminate\Database\Eloquent\Model;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\Principal;
use Spatie\Permission\Guard;

final class SpatieRoleResolver implements RoleResolver
{
    public function roles(Principal $principal): array
    {
        $principal->refresh();
        if (! $principal->user instanceof Model || ! method_exists($principal->user, 'roles')) {
            throw new \LogicException('The principal must use Spatie HasRoles.');
        }
        $guard = config('agent-access.roles.spatie_guard') ?? Guard::getDefaultName($principal->user);
        $roles = [];
        foreach ($principal->user->roles()->where('guard_name', $guard)->pluck('name') as $name) {
            if (! is_string($name)) {
                throw new \LogicException('Spatie role names must be strings.');
            }
            $roles[] = $name;
        }

        return $roles;
    }
}
