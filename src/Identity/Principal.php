<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final readonly class Principal
{
    public string $id;

    public function __construct(public Authenticatable $user, public string $type = 'user')
    {
        $this->id = (string) $user->getAuthIdentifier();
    }

    public function refresh(): void
    {
        if ($this->user instanceof Model) {
            $this->user->refresh();
        }
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
