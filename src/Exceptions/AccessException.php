<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Exceptions;

use LaravelAgentAccess\Authorization\AccessDecision;

final class AccessException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly int $status = 403, public readonly ?AccessDecision $decision = null,
        public readonly ?string $executionId = null, public readonly ?string $correlationId = null)
    {
        parent::__construct($reasonCode);
    }

    /**
     * @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->decision?->toArray() ?? array_filter(['schema_version' => 1, 'code' => $this->reasonCode,
            'execution_id' => $this->executionId, 'correlation_id' => $this->correlationId], fn ($value) => $value !== null);
    }
}
