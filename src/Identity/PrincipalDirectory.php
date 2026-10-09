<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LaravelAgentAccess\Exceptions\AccessException;

final class PrincipalDirectory
{
    public function find(string $id, ?string $type = null): Principal
    {
        $configured = (string) config('agent-access.principal_type', 'user');
        if ($type !== null && $type !== $configured) {
            throw new AccessException('PRINCIPAL_NOT_FOUND', 404);
        }
        $class = config('agent-access.principal_model') ?? config('auth.providers.users.model');
        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new \LogicException('Configure an Eloquent authenticatable principal model.');
        }
        $model = $class::query()->find($id);
        if (! $model instanceof Authenticatable) {
            throw new AccessException('PRINCIPAL_NOT_FOUND', 404);
        }

        return new Principal($model, $configured);
    }
}
