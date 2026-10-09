<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelAgentAccess\Adapters\Mcp\AgentAccessTool;

final class CancelTool extends AgentAccessTool
{
    protected string $name = 'cancel_order';

    protected string $description = 'Cancel an order using the current user access.';

    protected string $action = 'orders.cancel';

    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->integer()->required()];
    }
}
