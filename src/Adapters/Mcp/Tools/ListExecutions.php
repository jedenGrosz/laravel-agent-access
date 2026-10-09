<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Mcp\Tools;

use LaravelAgentAccess\Adapters\Mcp\AccessOperationTool;

final class ListExecutions extends AccessOperationTool
{
    protected string $name = 'access.executions';

    protected string $description = 'List the authenticated user’s durable execution receipts, optionally by correlation ID. Never retries actions.';

    protected function operation(): string
    {
        return 'executions';
    }
}
