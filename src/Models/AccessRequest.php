<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * @property string $id
 * @property string $principal_type
 * @property string $principal_id
 * @property string $action
 * @property string $permission
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property array<string, mixed> $bound_arguments
 * @property int $duration_seconds
 * @property int|null $max_uses
 * @property string $rule_id
 * @property string $rule_fingerprint
 * @property string $status
 * @property string|null $reason
 * @property string $payload_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $decided_at
 */
final class AccessRequest extends AccessModel
{
    use HasUlids;

    protected $table = 'agent_access_requests';

    protected $guarded = [];

    protected $hidden = ['rule_fingerprint', 'payload_hash', 'idempotency_key'];

    /**
     * @return array<string, string> */
    protected function casts(): array
    {
        return ['bound_arguments' => 'array', 'duration_seconds' => 'integer', 'max_uses' => 'integer', 'expires_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }
}
