<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelAgentAccess\Adapters\Mcp\AgentAccessTool;

final class DeleteTool extends AgentAccessTool
{
    protected string $name = 'delete_order';

    protected string $description = 'Delete an order using the current user access.';

    protected string $action = 'orders.delete';

    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->integer()->required()];
    }
}
