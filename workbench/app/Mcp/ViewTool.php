<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelAgentAccess\Adapters\Mcp\AgentAccessTool;

final class ViewTool extends AgentAccessTool
{
    protected string $name = 'view_order';

    protected string $description = 'View an order using the current user access.';

    protected string $action = 'orders.view';

    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->integer()->required()];
    }
}
