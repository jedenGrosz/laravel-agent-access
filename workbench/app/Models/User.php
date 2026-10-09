<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $department
 * @property bool $is_suspended */
final class User extends Authenticatable
{
    use HasApiTokens, HasRoles;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return ['is_suspended' => 'boolean'];
    }
}
