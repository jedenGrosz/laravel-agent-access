<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Mcp\Tools;

use LaravelAgentAccess\Adapters\Mcp\AccessOperationTool;

final class ShowExecution extends AccessOperationTool
{
    protected string $name = 'access.execution';

    protected string $description = 'Read durable admission/outcome evidence for an authorized execution ID. This never resumes an action.';

    protected function operation(): string
    {
        return 'execution';
    }
}
