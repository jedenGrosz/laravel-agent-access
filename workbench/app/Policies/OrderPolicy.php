<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Illuminate\Auth\Access\Response;
use LaravelAgentAccess\Authorization\IamDecision;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

final class OrderPolicy
{
    public function view(User $user, Order $order, array $arguments = []): Response
    {
        return $this->check($user, 'orders.view');
    }

    public function cancel(User $user, Order $order, array $arguments = []): Response
    {
        return $order->is_refunded || $order->is_cancelled ? Response::deny() : $this->check($user, 'orders.cancel');
    }

    public function delete(User $user, Order $order, array $arguments = []): Response
    {
        return $this->check($user, 'orders.delete');
    }

    public function refund(User $user, Order $order, array $arguments = []): Response
    {
        if ($order->is_refunded || $order->is_cancelled || ($arguments['amount_minor'] ?? 0) > $order->amount_minor || ($arguments['currency'] ?? '') !== $order->currency) {
            return Response::deny();
        }

        return $this->check($user, 'orders.refund');
    }

    private function check(User $user, string $permission): Response
    {
        if ($user->is_suspended) {
            return Response::deny();
        }

        return $user->can($permission) ? Response::allow() : Response::deny('Permission is required.', IamDecision::MISSING_PERMISSION_CODE);
    }
}
