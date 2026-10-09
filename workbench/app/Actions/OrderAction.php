<?php

declare(strict_types=1);

namespace Workbench\App\Actions;

use LaravelAgentAccess\Actions\Action;
use Workbench\App\Models\Order;

abstract class OrderAction extends Action
{
    public function rules(): array
    {
        return ['order_id' => 'required|integer|min:1'];
    }

    public function normalize(array $arguments): array
    {
        $arguments['order_id'] = (int) $arguments['order_id'];

        return $arguments;
    }

    public function resource(array $arguments): Order
    {
        return Order::query()->findOrFail($arguments['order_id']);
    }
}
