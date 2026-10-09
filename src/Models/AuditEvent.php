<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $event_type
 * @property array<string, mixed> $metadata
 * @property Carbon|null $created_at
 */
final class AuditEvent extends AccessModel
{
    use HasUlids;

    protected $table = 'agent_access_audit_events';

    protected $guarded = [];

    /**
     * @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
