<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Mcp\Tools;

use LaravelAgentAccess\Adapters\Mcp\AccessOperationTool;

final class ListActions extends AccessOperationTool
{
    protected string $name = 'access.actions';

    protected string $description = 'Discover registered actions and candidate grant rules. This catalog does not authorize execution.';

    protected function operation(): string
    {
        return 'actions';
    }
}
