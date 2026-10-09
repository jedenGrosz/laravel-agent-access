<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class CustomIdentityUser extends User
{
    protected $table = 'string_users';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function getAuthIdentifierName(): string
    {
        return 'identity';
    }
}
