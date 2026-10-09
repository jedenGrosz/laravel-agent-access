<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;
use Workbench\App\Models\Order;

final class WorkflowTest extends TestCase
{
    public function test_complete_refund_flow(): void
    {
        $decision = app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args());
        self::assertFalse($decision->allowed);
        self::assertTrue($decision->toArray()['requestable']);
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $grant = app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        self::assertSame(0, $grant->uses);
        self::assertTrue($grant->expires_at->equalTo($grant->valid_from->addSeconds($grant->duration_seconds)));
        self::assertTrue(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
        self::assertSame(0, $grant->fresh()->uses);
        $result = app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args());
        self::assertTrue($result['refunded']);
        self::assertSame(1, $grant->fresh()->uses);
        self::assertSame(1, DB::table('refunds')->count());
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
        self::assertSame(['ACCESS_CHECK_DENIED', 'ACCESS_REQUEST_CREATED', 'ACCESS_REQUEST_APPROVED', 'GRANT_CREATED', 'GRANT_USED', 'ACTION_STARTED', 'ACTION_SUCCEEDED', 'ACCESS_CHECK_DENIED'], AuditEvent::query()->pluck('event_type')->all());
    }

    public function test_baseline_access_needs_no_grant(): void
    {
        self::assertTrue(app(DecisionService::class)->check($this->context(), 'orders.view', ['order_id' => 431])->allowed);
        app(ActionExecutor::class)->execute($this->context(), 'orders.view', ['order_id' => 431]);
        self::assertSame(0, Grant::query()->count());
    }

    public function test_changed_amount_and_forbidden_state_cannot_use_grant(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args(amount: 11000))->allowed);
        Order::query()->find(431)->update(['is_refunded' => true]);
        self::assertSame('FORBIDDEN', app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->reasonCode);
    }

    public function test_manager_role_is_rechecked_at_approval(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->manager->removeRole('finance_manager');
        $this->expectException(AccessException::class);
        app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
    }
}
