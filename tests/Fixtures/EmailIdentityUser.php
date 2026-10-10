<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class EmailIdentityUser extends User
{
    protected $table = 'users';

    public function getAuthIdentifierName(): string
    {
        return 'email';
    }
}
