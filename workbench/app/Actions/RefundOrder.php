<?php

declare(strict_types=1);

namespace Workbench\App\Actions;

use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;
use Workbench\App\Models\Order;

#[RequiresPermission('orders.refund', ability: 'refund', resourceArgument: 'order_id', resourceType: 'order')]
final class RefundOrder extends OrderAction
{
    public function key(): string
    {
        return 'orders.refund';
    }

    public function rules(): array
    {
        return parent::rules() + ['amount_minor' => 'required|integer|min:1', 'currency' => 'required|string|size:3'];
    }

    public function normalize(array $arguments): array
    {
        $arguments = parent::normalize($arguments);
        $arguments['amount_minor'] = (int) $arguments['amount_minor'];
        $arguments['currency'] = strtoupper($arguments['currency']);

        return $arguments;
    }

    public function bindableArguments(): array
    {
        return ['amount_minor', 'currency'];
    }

    public function handle(ExecutionContext $context, AccessRequirement $requirement): array
    {
        return DB::transaction(function () use ($requirement): array {
            $order = Order::query()->lockForUpdate()->findOrFail($requirement->resourceId);
            if ($order->is_refunded || $order->is_cancelled || $requirement->arguments['amount_minor'] > $order->amount_minor || $requirement->arguments['currency'] !== $order->currency) {
                throw new AccessException('ORDER_STATE_CHANGED', 409);
            }
            DB::table('refunds')->insert(['order_id' => $order->id, 'amount_minor' => $requirement->arguments['amount_minor'], 'currency' => $requirement->arguments['currency']]);
            $order->is_refunded = true;
            $order->save();

            return ['order_id' => $order->id, 'refunded' => true];
        });
    }
}
