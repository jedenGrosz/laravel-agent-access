<?php

declare(strict_types=1);

namespace Workbench\App\Actions;

use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;
use Workbench\App\Models\Order;

#[RequiresPermission('orders.cancel', ability: 'cancel', resourceArgument: 'order_id', resourceType: 'order')]
final class CancelOrder extends OrderAction
{
    public function key(): string
    {
        return 'orders.cancel';
    }

    public function handle(ExecutionContext $context, AccessRequirement $requirement): array
    {
        return DB::transaction(function () use ($requirement): array {
            $order = Order::query()->lockForUpdate()->findOrFail($requirement->resourceId);
            if ($order->is_cancelled || $order->is_refunded) {
                throw new AccessException('ORDER_STATE_CHANGED', 409);
            }
            $order->is_cancelled = true;
            $order->save();

            return ['order_id' => $order->id, 'cancelled' => true];
        });
    }
}
