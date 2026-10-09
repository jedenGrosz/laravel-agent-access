<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Rules\GrantRule;
use LaravelAgentAccess\Rules\RuleRegistry;
use Tests\Fixtures\ExportReport;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

final class PublicContractTest extends TestCase
{
    private ExportReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new ExportReport;
        app(ActionRegistry::class)->register($this->report);
        app(RuleRegistry::class)->register(GrantRule::named('report-export')->forPermission('reports.export')->allowGlobalScope()
            ->grantableBy('finance_manager')->toRole('support')->bindArguments(['classification', 'limit']));
    }

    public function test_resource_less_gate_receives_normalized_arguments_and_hard_denial_blocks_requests(): void
    {
        Gate::define('exportReport', static fn (User $user, array $arguments = []): Response => ($arguments['classification'] ?? 'public') === 'restricted' ? Response::deny('Restricted data.')
                : Response::deny('Permission required.', IamDecision::MISSING_PERMISSION_CODE));
        $decision = app(DecisionService::class)->check($this->context(), 'reports.export', ['classification' => ' RESTRICTED ', 'limit' => '10']);
        self::assertSame('FORBIDDEN', $decision->reasonCode);
        self::assertFalse($decision->toArray()['requestable']);
        try {
            app(AccessRequestService::class)->create($this->context(), 'reports.export', ['classification' => 'restricted', 'limit' => 10]);
            self::fail('A hard parameter denial cannot become an access request.');
        } catch (AccessException $exception) {
            self::assertSame(403, $exception->status);
        }
        self::assertSame(0, AccessRequest::query()->count());
        self::assertSame(0, $this->report->attempts);
    }

    public function test_resource_less_policy_rechecks_arguments_at_approval_and_before_using_a_valid_grant(): void
    {
        $embargo = false;
        $seen = [];
        Gate::define('exportReport', static function (User $user, array $arguments = []) use (&$embargo, &$seen): Response {
            $seen[] = $arguments;

            return $embargo ? Response::deny('An export embargo applies.')
                : Response::deny('Permission required.', IamDecision::MISSING_PERMISSION_CODE);
        });
        $input = ['classification' => ' PUBLIC ', 'limit' => '10'];
        $normalized = ['classification' => 'public', 'limit' => 10];
        $request = app(AccessRequestService::class)->create($this->context(), 'reports.export', $input);
        $bound = $request->bound_arguments;
        ksort($bound); // MySQL JSON objects may reorder keys; scalar types must remain exact.
        self::assertSame($normalized, $bound);
        $grant = app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        self::assertSame($normalized, $seen[0]);
        $approved = $seen[1];
        ksort($approved);
        self::assertSame($normalized, $approved);
        $embargo = true;
        try {
            app(ActionExecutor::class)->execute($this->context(), 'reports.export', $input);
            self::fail('The baseline hard denial must override the valid grant.');
        } catch (AccessException $exception) {
            self::assertSame('FORBIDDEN', $exception->decision->reasonCode);
        }
        self::assertSame(0, $grant->fresh()->uses);
        self::assertSame(0, $this->report->attempts);
        $embargo = false;
        $result = app(ActionExecutor::class)->execute($this->context(), 'reports.export', $input);
        self::assertSame($normalized, $result);
        self::assertSame($result, $seen[array_key_last($seen)]);
        self::assertSame(1, $this->report->attempts);
        self::assertSame(1, $grant->fresh()->uses);
    }

    public function test_resource_policy_receives_the_resolved_model_and_the_same_normalized_handler_arguments(): void
    {
        $policy = new class
        {
            public array $seen = [];

            public array $resourceIds = [];

            public function view(User $user, Order $order, array $arguments): Response
            {
                $this->resourceIds[] = $order->id;
                $this->seen[] = $arguments;

                return Response::allow();
            }
        };
        app()->instance($policy::class, $policy);
        Gate::policy(Order::class, $policy::class);
        $result = app(ActionExecutor::class)->execute($this->context(), 'orders.view', ['order_id' => '431']);
        self::assertSame(431, $result['id']);
        self::assertSame([431, 431], $policy->resourceIds);
        self::assertSame([['order_id' => 431], ['order_id' => 431]], $policy->seen);
    }
}
