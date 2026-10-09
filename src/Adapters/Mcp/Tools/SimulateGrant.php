<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Mcp\Tools;

use LaravelAgentAccess\Adapters\Mcp\AccessOperationTool;

final class SimulateGrant extends AccessOperationTool
{
    protected string $name = 'access.simulate';

    protected string $description = 'Preview one grant rule without issuing access. Inspecting another recipient requires explicit host authorization.';

    protected function operation(): string
    {
        return 'simulate';
    }
}
