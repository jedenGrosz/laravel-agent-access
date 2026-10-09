<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Models\GrantUse;
use LaravelAgentAccess\Requirements\RequirementResolver;

final readonly class ActionExecutor
{
    public function __construct(private ActionRegistry $actions, private RequirementResolver $requirements,
        private DecisionService $decisions, private GrantService $grants, private AuditWriter $audit) {}

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    public function execute(ExecutionContext $context, string $action, array $arguments): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Execute outside an outer transaction: admission must commit before the handler runs.');
        }
        $executionId = (string) Str::ulid();
        $requirement = $this->requirements->resolve($action, $arguments);
        $decision = $this->decisions->evaluate($context, $requirement);
        if (! $decision->allowed) {
            $this->audit->record('ACCESS_CHECK_DENIED', $context, ['execution_id' => $executionId, 'reason_code' => $decision->reasonCode]);
            throw new AccessException('ACCESS_DENIED', 403, $decision);
        }
        try {
            DB::transaction(function () use ($context, $action, $arguments, $decision, $executionId, &$requirement): void {
                $locked = $decision->grantId === null ? null : Grant::query()->lockForUpdate()->find($decision->grantId);
                $requirement = $this->requirements->resolve($action, $arguments);
                $fresh = $this->decisions->evaluate($context, $requirement);
                if (! $fresh->allowed) {
                    throw new AccessException('ACCESS_DENIED', 403, $fresh);
                }
                if ($fresh->source === 'grant') {
                    if ($locked === null || $locked->id !== $fresh->grantId || ! $this->grants->matches($locked, $context, $requirement)) {
                        throw new AccessException('GRANT_UNAVAILABLE', 409);
                    }
                    $locked->uses++;
                    $locked->save();
                    GrantUse::query()->create(['grant_id' => $locked->id, 'execution_id' => $executionId, 'principal_type' => $context->principal->type, 'principal_id' => $context->principal->id]);
                    $this->audit->record('GRANT_USED', $context, ['grant_id' => $locked->id, 'execution_id' => $executionId]);
                }
                $this->audit->record('ACTION_STARTED', $context, ['grant_id' => $fresh->grantId, 'execution_id' => $executionId, 'source' => $fresh->source]);
            });
        } catch (AccessException $exception) {
            // The admission transaction rolled back; persist the denial outside it.
            $this->audit->record('ACCESS_CHECK_DENIED', $context, ['execution_id' => $executionId,
                'reason_code' => $exception->decision->reasonCode ?? $exception->reasonCode]);
            throw $exception;
        }
        try {
            $result = $this->actions->get($action)->handle($context, $requirement);
        } catch (\Throwable $exception) {
            try {
                $this->audit->record('ACTION_FAILED', $context, ['execution_id' => $executionId]);
            } catch (\Throwable) { /* Durable ACTION_STARTED remains. */
            }
            throw $exception;
        }
        try {
            $this->audit->record('ACTION_SUCCEEDED', $context, ['execution_id' => $executionId]);
        } catch (\Throwable) {
            throw new AccessException('ACTION_RESULT_UNRECORDED', 500, executionId: $executionId, correlationId: $context->correlationId);
        }

        return $result;
    }
}
