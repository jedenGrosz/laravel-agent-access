<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Audit;

use LaravelAgentAccess\Contracts\InspectionAuthorizer;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\AuditEvent;

final readonly class ExecutionReceiptService
{
    public function __construct(private InspectionAuthorizer $inspection) {}

    /** Owned executions only, including attempts whose HTTP result was lost.
     * @return array<string, mixed> */
    public function list(ExecutionContext $context, int $page = 1, int $perPage = 20, ?string $correlationId = null): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new AccessException('INVALID_PAGINATION', 422);
        }
        $query = AuditEvent::query()->where('actor_type', $context->principal->type)->where('actor_id', $context->principal->id)
            ->whereNotNull('execution_id')->whereIn('event_type', ['ACTION_STARTED', 'ACCESS_CHECK_DENIED']);
        if ($query->getModel()->getConnection()->getDriverName() === 'mysql') {
            // MySQL's common case-insensitive collations must not merge distinct identity realms/IDs.
            $query->whereRaw('CAST(actor_type AS BINARY) = ?', [$context->principal->type])
                ->whereRaw('CAST(actor_id AS BINARY) = ?', [$context->principal->id]);
        }
        if ($correlationId !== null) {
            $query->where('correlation_id', $correlationId);
        }
        $records = $query->orderByDesc('id')->paginate($perPage, ['execution_id'], 'page', $page);

        return ['data' => array_map(fn (AuditEvent $event): array => $this->show($context, $event->getAttribute('execution_id')), $records->items()),
            'total' => $records->total(), 'current_page' => $records->currentPage(), 'per_page' => $records->perPage()];
    }

    /** Reads durable evidence only; never reruns a handler or restores a use.
     * @return array<string, mixed> */
    public function show(ExecutionContext $context, string $executionId): array
    {
        $events = AuditEvent::query()->where('execution_id', $executionId)
            ->whereIn('event_type', ['ACTION_STARTED', 'ACTION_SUCCEEDED', 'ACTION_FAILED', 'ACCESS_CHECK_DENIED'])
            ->orderBy('id')->get();
        $anchor = $events->firstWhere('event_type', 'ACTION_STARTED') ?? $events->firstWhere('event_type', 'ACCESS_CHECK_DENIED');
        if ($anchor === null || $anchor->getAttribute('actor_type') !== (string) config('agent-access.principal_type', 'user')) {
            throw new AccessException('EXECUTION_NOT_FOUND', 404);
        }
        $owns = $anchor->getAttribute('actor_type') === $context->principal->type && $anchor->getAttribute('actor_id') === $context->principal->id;
        if (! $owns && ! $this->inspection->allows($context, $anchor->getAttribute('actor_id'))) {
            throw new AccessException('EXECUTION_NOT_FOUND', 404);
        }
        $started = $events->contains('event_type', 'ACTION_STARTED');
        $status = match (true) {
            $events->contains('event_type', 'ACTION_SUCCEEDED') => 'SUCCEEDED',
            $events->contains('event_type', 'ACTION_FAILED') => 'FAILED',
            $started => 'OUTCOME_UNKNOWN',
            default => 'DENIED',
        };
        $metadata = $anchor->metadata;

        return ['schema_version' => 1, 'code' => 'EXECUTION_RECEIPT', 'execution_id' => $executionId,
            'correlation_id' => $anchor->getAttribute('correlation_id'), 'status' => $status, 'admitted' => $started,
            'requires_review' => in_array($status, ['OUTCOME_UNKNOWN', 'FAILED'], true),
            'action' => $metadata['action'] ?? null, 'permission' => $metadata['permission'] ?? null,
            'resource' => isset($metadata['resource_type']) ? ['type' => $metadata['resource_type'], 'id' => $metadata['resource_id'] ?? null] : null,
            'grant_id' => $anchor->getAttribute('grant_id'),
            'reason_code' => $metadata['reason_code'] ?? null,
            'events' => $events->map(fn (AuditEvent $event): array => ['type' => $event->event_type, 'at' => $event->created_at?->toISOString()])->all()];
    }
}
