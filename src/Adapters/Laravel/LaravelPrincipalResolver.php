<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Laravel;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use LaravelAgentAccess\Contracts\PrincipalResolver;
use LaravelAgentAccess\Identity\Principal;

final readonly class LaravelPrincipalResolver implements PrincipalResolver
{
    public function __construct(private Factory $auth) {}

    public function resolve(?Authenticatable $user = null): Principal
    {
        $user ??= $this->auth->guard()->user();
        if ($user === null) {
            throw new AuthenticationException;
        }
        $class = config('agent-access.principal_model') ?? config('auth.providers.users.model');
        if (! is_string($class) || ! class_exists($class) || $user::class !== (new \ReflectionClass($class))->getName()) {
            throw new AuthenticationException('An authenticated host user is required.');
        }

        return new Principal($user, (string) config('agent-access.principal_type', 'user'));
    }
}
