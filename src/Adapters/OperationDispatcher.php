<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters;

use Illuminate\Contracts\Validation\Factory;
use LaravelAgentAccess\Actions\ActionCatalog;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Audit\ExecutionReceiptService;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Authorization\GrantSimulationService;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requests\QueryService;

final readonly class OperationDispatcher
{
    public function __construct(private Factory $validation, private DecisionService $decisions, private ActionExecutor $executor,
        private AccessRequestService $requests, private ApprovalService $approvals, private GrantService $grants, private QueryService $queries,
        private GrantSimulationService $simulation, private ActionCatalog $catalog, private ExecutionReceiptService $receipts) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function dispatch(ExecutionContext $context, string $operation, array $input): array
    {
        $rules = ['user_id' => 'prohibited', 'principal' => 'prohibited', 'actor_id' => 'prohibited', 'target_user_id' => 'prohibited', 'target' => 'prohibited', 'roles' => 'prohibited'];
        if (in_array($operation, ['check', 'execute', 'run', 'request', 'simulate'], true)) {
            $rules += ['action' => 'required|string|max:128', 'arguments' => 'present|array'];
        }
        if ($operation === 'request') {
            $rules += ['rule_id' => 'nullable|string|max:128', 'duration_seconds' => 'nullable|integer|min:1', 'max_uses' => 'nullable|integer|min:1', 'reason' => 'nullable|string|max:500', 'idempotency_key' => 'nullable|string|max:128'];
        }
        if ($operation === 'simulate') {
            $rules += ['rule_id' => 'required|string|max:128', 'recipient_id' => 'nullable|string|max:128',
                'duration_seconds' => 'nullable|integer|min:1', 'max_uses' => 'nullable|integer|min:1'];
        }
        if (in_array($operation, ['show', 'approve', 'reject', 'cancel', 'revoke'], true)) {
            $rules += ['id' => 'required|string|max:26', 'reason' => 'nullable|string|max:500'];
        }
        if ($operation === 'execution') {
            $rules += ['id' => 'required|string|max:26'];
        }
        if (in_array($operation, ['requests', 'grants', 'executions'], true)) {
            $rules += ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'status' => 'nullable|in:pending,approved,rejected,cancelled,expired'];
        }
        if ($operation === 'executions') {
            $rules += ['correlation_id' => 'nullable|string|max:128'];
        }
        $valid = $this->validation->make($input, $rules)->validate();
        $action = (string) ($valid['action'] ?? '');
        /** @var array<string, mixed> $arguments */
        $arguments = $valid['arguments'] ?? [];
        $id = (string) ($valid['id'] ?? '');

        return match ($operation) {
            'actions' => $this->catalog->list($context),
            'check' => $this->decisions->check($context, $action, $arguments)->toArray(),
            'execute' => $this->executor->execute($context, $action, $arguments),
            'run' => $this->executor->executeWithReceipt($context, $action, $arguments)->toArray(),
            'execution' => $this->receipts->show($context, $id),
            'executions' => $this->receipts->list($context, (int) ($valid['page'] ?? 1), (int) ($valid['per_page'] ?? 20), $valid['correlation_id'] ?? null),
            'simulate' => $this->simulation->simulate($context, $action, $arguments, $valid['rule_id'], $valid['recipient_id'] ?? null,
                isset($valid['duration_seconds']) ? (int) $valid['duration_seconds'] : null,
                array_key_exists('max_uses', $valid) ? ($valid['max_uses'] === null ? null : (int) $valid['max_uses']) : (int) config('agent-access.grants.default_uses', 1)),
            'request' => $this->requests->create($context, $action, $arguments, $valid['rule_id'] ?? null,
                isset($valid['duration_seconds']) ? (int) $valid['duration_seconds'] : null,
                array_key_exists('max_uses', $valid) ? ($valid['max_uses'] === null ? null : (int) $valid['max_uses']) : (int) config('agent-access.grants.default_uses', 1),
                $valid['reason'] ?? null, $valid['idempotency_key'] ?? null)->toArray(),
            'show' => $this->requests->show($context, $id)->toArray(),
            'approve' => $this->approvals->approve($context, $id)->toArray(),
            'reject' => $this->approvals->reject($context, $id, $valid['reason'] ?? null)->toArray(),
            'cancel' => $this->requests->cancel($context, $id)->toArray(),
            'revoke' => $this->grants->revoke($context, $id)->toArray(),
            'requests' => $this->queries->requests($context, (int) ($valid['page'] ?? 1), (int) ($valid['per_page'] ?? 20), $valid['status'] ?? null),
            'grants' => $this->queries->grants($context, (int) ($valid['page'] ?? 1), (int) ($valid['per_page'] ?? 20)),
            default => throw new \InvalidArgumentException('Unknown access operation.'),
        };
    }
}
