<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Mcp\Tools;

use LaravelAgentAccess\Adapters\Mcp\AccessOperationTool;

final class RunAction extends AccessOperationTool
{
    protected string $name = 'access.run';

    protected string $description = 'Execute a registered action through IAM and return its execution receipt ID. One use permits one attempt.';

    protected function operation(): string
    {
        return 'run';
    }
}
