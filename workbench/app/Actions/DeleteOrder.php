<?php

declare(strict_types=1);

namespace Workbench\App\Actions;

use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;

#[RequiresPermission('orders.delete', ability: 'delete', resourceArgument: 'order_id', resourceType: 'order')]
final class DeleteOrder extends OrderAction
{
    public function key(): string
    {
        return 'orders.delete';
    }

    public function handle(ExecutionContext $context, AccessRequirement $requirement): array
    {
        $requirement->resource->delete();

        return ['order_id' => $requirement->resourceId, 'deleted' => true];
    }
}
