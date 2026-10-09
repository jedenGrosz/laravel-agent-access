<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelAgentAccess\Adapters\Mcp\AgentAccessTool;

final class RefundTool extends AgentAccessTool
{
    protected string $name = 'refund_order';

    protected string $description = 'Refund an order. May require a scoped access request.';

    protected string $action = 'orders.refund';

    public function schema(JsonSchema $schema): array
    {
        return ['order_id' => $schema->integer()->required(), 'amount_minor' => $schema->integer()->min(1)->required(), 'currency' => $schema->string()->required()];
    }
}
