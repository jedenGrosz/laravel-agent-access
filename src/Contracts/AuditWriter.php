<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use LaravelAgentAccess\Identity\ExecutionContext;

interface AuditWriter
{
    /**
     * @param array<string, scalar|null> $references */
    public function record(string $event, ExecutionContext $context, array $references = []): void;
}
