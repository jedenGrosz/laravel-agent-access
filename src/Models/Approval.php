<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * @property string $actor_type
 * @property string $actor_id
 * @property string $decision */
final class Approval extends AccessModel
{
    use HasUlids;

    protected $table = 'agent_access_approvals';

    protected $guarded = [];
}
