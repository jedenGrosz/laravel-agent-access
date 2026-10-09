<?php

declare(strict_types=1);

namespace Workbench\App\Actions;

use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;

#[RequiresPermission('orders.view', ability: 'view', resourceArgument: 'order_id', resourceType: 'order')]
final class ViewOrder extends OrderAction
{
    public function key(): string
    {
        return 'orders.view';
    }

    public function handle(ExecutionContext $context, AccessRequirement $requirement): array
    {
        return $requirement->resource->toArray();
    }
}
