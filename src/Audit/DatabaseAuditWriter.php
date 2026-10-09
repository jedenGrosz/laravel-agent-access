<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Audit;

use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Events\AccessEvent;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\AuditEvent;

final class DatabaseAuditWriter implements AuditWriter
{
    public function record(string $event, ExecutionContext $context, array $references = []): void
    {
        $safe = array_intersect_key($references, array_flip(['request_id', 'grant_id', 'execution_id', 'rule_id', 'reason_code', 'source',
            'action', 'permission', 'resource_type', 'resource_id']));
        $metadata = array_diff_key($safe, array_flip(['request_id', 'grant_id', 'execution_id']));
        foreach (['client_id', 'session_id'] as $key) {
            if (isset($context->trustedMetadata[$key])) {
                $metadata['trusted_'.$key] = substr($context->trustedMetadata[$key], 0, 128);
            }
            if (isset($context->declaredMetadata[$key])) {
                $metadata['declared_'.$key] = substr($context->declaredMetadata[$key], 0, 128);
            }
        }
        AuditEvent::query()->create(['event_type' => $event, 'actor_type' => $context->principal->type, 'actor_id' => $context->principal->id,
            'request_id' => $safe['request_id'] ?? null, 'grant_id' => $safe['grant_id'] ?? null, 'execution_id' => $safe['execution_id'] ?? null,
            'channel' => $context->channel, 'correlation_id' => $context->correlationId, 'metadata' => $metadata]);
        $notification = new AccessEvent($event, $context->correlationId, $safe);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => event($notification));
        } else {
            event($notification);
        }
    }
}
