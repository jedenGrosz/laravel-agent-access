<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * @property string $id
 * @property string $request_id
 * @property string $principal_type
 * @property string $principal_id
 * @property string $action
 * @property string $permission
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property array<string, mixed> $bound_arguments
 * @property string $rule_id
 * @property string $rule_fingerprint
 * @property int $duration_seconds
 * @property int|null $max_uses
 * @property int $uses
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $expiry_recorded_at
 */
final class Grant extends AccessModel
{
    use HasUlids;

    protected $table = 'agent_access_grants';

    protected $guarded = [];

    protected $hidden = ['rule_fingerprint'];

    /**
     * @return array<string, string> */
    protected function casts(): array
    {
        return ['bound_arguments' => 'array', 'duration_seconds' => 'integer', 'uses' => 'integer', 'max_uses' => 'integer',
            'valid_from' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'expiry_recorded_at' => 'immutable_datetime'];
    }
}
