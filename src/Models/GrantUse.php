<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class GrantUse extends AccessModel
{
    use HasUlids;

    protected $table = 'agent_access_grant_uses';

    protected $guarded = [];
}
