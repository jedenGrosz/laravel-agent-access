<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

use Illuminate\Contracts\Container\Container;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Requirements\AccessRequirement;

final readonly class GrantPolicy
{
    public function __construct(private RoleResolver $roles, private Container $container) {}

    public function requestable(Principal $target, AccessRequirement $requirement, GrantConstraints $constraints, GrantRule $rule): bool
    {
        $target->refresh();
        if ($rule->permission !== $requirement->permission || ! array_intersect($rule->targetRoles, $this->roles->roles($target)) ||
            $constraints->durationSeconds > min($rule->maximumDuration, (int) config('agent-access.grants.max_ttl', 86400)) ||
            ($rule->resourceRequired && $requirement->resourceId === null) ||
            ($rule->maximumUses !== null && ($constraints->maxUses === null || $constraints->maxUses > $rule->maximumUses))) {
            return false;
        }
        foreach ($rule->boundArguments as $name) {
            if (! array_key_exists($name, $requirement->arguments) || is_float($requirement->arguments[$name]) || (! is_scalar($requirement->arguments[$name]) && $requirement->arguments[$name] !== null)) {
                return false;
            }
        }
        foreach ($rule->conditions as $class) {
            if (! $this->container->make($class)->requestable($target, $requirement, $constraints)) {
                return false;
            }
        }

        return true;
    }

    public function allows(Principal $actor, Principal $target, AccessRequirement $requirement, GrantConstraints $constraints, GrantRule $rule): bool
    {
        $actor->refresh();
        if ($actor->equals($target) || ! $this->requestable($target, $requirement, $constraints, $rule) ||
            ! array_intersect($rule->grantorRoles, $this->roles->roles($actor))) {
            return false;
        }
        foreach ($rule->conditions as $class) {
            if (! $this->container->make($class)->allows($actor, $target, $requirement, $constraints)) {
                return false;
            }
        }

        return true;
    }
}
