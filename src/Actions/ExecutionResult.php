<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Actions;

final readonly class ExecutionResult
{
    /** @param array<string, mixed> $result */
    public function __construct(public string $executionId, public string $correlationId, public array $result) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['schema_version' => 1, 'code' => 'ACTION_EXECUTED', 'execution_id' => $this->executionId,
            'correlation_id' => $this->correlationId, 'result' => $this->result];
    }
}
