<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use LaravelAgentAccess\Actions\Action;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Adapters\Mcp\Tools\RequestAccess;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Authorization\IamOutcome;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;
use LaravelAgentAccess\Rules\GrantRule;
use LaravelAgentAccess\Rules\RuleRegistry;

final class ReleaseReadinessTest extends TestCase
{
    private function rule(?int $uses): void
    {
        $rule = GrantRule::named('refund-defaults')->forPermission('orders.refund')
            ->grantableBy('finance_manager')->toRole('support')->bindArguments(['amount_minor', 'currency']);
        app()->instance(RuleRegistry::class, new RuleRegistry([$uses === null ? $rule->unlimitedUses() : $rule->maxUses($uses)]));
    }

    public function test_default_usage_limit_is_identical_for_php_http_mcp_and_artisan(): void
    {
        config(['agent-access.grants.default_uses' => 2]);
        $this->rule(3);
        $input = ['action' => 'orders.refund', 'arguments' => $this->args()];
        $php = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), reason: 'PHP default');
        self::assertSame(2, $php->max_uses);
        Sanctum::actingAs($this->support);
        $this->postJson('/agent-access/v1/requests', $input)->assertOk()->assertJsonPath('max_uses', 2);
        $this->actingAs($this->support);
        $mcp = (new RequestAccess)->handle(new Request($input));
        self::assertFalse($mcp->responses()->first()->isError());
        self::assertSame(2, json_decode((string) $mcp->responses()->first()->content(), true)['max_uses']);
        $this->artisan('agent-access:request', ['--as' => (string) $this->support->id, '--input' => json_encode($input)])->assertSuccessful();
        self::assertSame([2, 2, 2, 2], AccessRequest::query()->pluck('max_uses')->all());
    }

    public function test_omitted_and_explicit_configured_usage_limit_have_same_idempotent_payload(): void
    {
        config(['agent-access.grants.default_uses' => 2]);
        $this->rule(3);
        $first = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), idempotencyKey: 'default-uses');
        $retry = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), maxUses: 2, idempotencyKey: 'default-uses');
        self::assertSame($first->id, $retry->id);
        self::assertSame(1, AccessRequest::query()->count());
    }

    public function test_explicit_unlimited_uses_requires_rule_permission(): void
    {
        $this->rule(null);
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), maxUses: null);
        self::assertNull($request->max_uses);
        self::assertNull(app(ApprovalService::class)->approve($this->context($this->manager), $request->id)->max_uses);
        $this->rule(1);
        $this->expectException(AccessException::class);
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(amount: 11000), ruleId: 'refund-defaults', maxUses: null);
    }

    public function test_default_limit_exceeding_rule_is_rejected_instead_of_silently_clamped(): void
    {
        config(['agent-access.grants.default_uses' => 2]);
        $this->rule(1);
        self::assertTrue(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->toArray()['requestable']);
        try {
            app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), ruleId: 'refund-defaults');
            self::fail('An oversized default must not be silently reduced.');
        } catch (AccessException $exception) {
            self::assertSame('INVALID_CONSTRAINTS', $exception->reasonCode);
        }
        self::assertSame(0, AccessRequest::query()->count());
        $explicit = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), ruleId: 'refund-defaults', maxUses: 1);
        self::assertSame(1, $explicit->max_uses);
    }

    public function test_cached_principal_cannot_keep_baseline_access_after_suspension(): void
    {
        $context = $this->context();
        self::assertTrue(app(DecisionService::class)->check($context, 'orders.view', ['order_id' => 431])->allowed);
        DB::table('users')->where('id', $this->support->id)->update(['is_suspended' => true]);
        try {
            app(ActionExecutor::class)->execute($context, 'orders.view', ['order_id' => 431]);
            self::fail('A cached principal must be reloaded.');
        } catch (AccessException $exception) {
            self::assertSame('FORBIDDEN', $exception->decision->reasonCode);
        }
        self::assertSame(0, AuditEvent::query()->where('event_type', 'ACTION_STARTED')->count());
    }

    public function test_admission_rechecks_principal_state_changed_after_preview(): void
    {
        app()->instance(IamAdapter::class, new class implements IamAdapter
        {
            public function evaluate(Principal $principal, AccessRequirement $requirement): IamDecision
            {
                if ($principal->user->is_suspended) {
                    return new IamDecision(IamOutcome::Forbidden);
                }
                DB::table('users')->where('id', $principal->id)->update(['is_suspended' => true]);

                return new IamDecision(IamOutcome::Allow);
            }
        });
        try {
            app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args());
            self::fail('State changed between preview and admission.');
        } catch (AccessException $exception) {
            self::assertSame('FORBIDDEN', $exception->decision->reasonCode);
        }
        self::assertSame(0, DB::table('refunds')->count());
        self::assertSame(0, AuditEvent::query()->where('event_type', 'ACTION_STARTED')->count());
        self::assertSame(1, AuditEvent::query()->where('event_type', 'ACCESS_CHECK_DENIED')->where('metadata->reason_code', 'FORBIDDEN')->count());
    }

    public function test_approval_rechecks_current_hard_denial(): void
    {
        $this->rule(1);
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        DB::table('users')->where('id', $this->support->id)->update(['is_suspended' => true]);
        try {
            app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
            self::fail('Suspension must prevent issuance.');
        } catch (AccessException $exception) {
            self::assertSame('REQUEST_NO_LONGER_GRANTABLE', $exception->reasonCode);
        }
        self::assertSame('pending', $request->fresh()->status);
        self::assertSame(0, Grant::query()->count());
    }

    public function test_validate_command_checks_action_mapping_using_shared_validator(): void
    {
        $action = new #[RequiresPermission('fixture.check', ability: 'view')] class extends Action
        {
            public function key(): string
            {
                return 'fixture.check';
            }

            public function rules(): array
            {
                return ['value' => 'required|string'];
            }

            public function bindableArguments(): array
            {
                return ['value', 'value'];
            }

            public function handle(ExecutionContext $context, AccessRequirement $requirement): array
            {
                return [];
            }
        };
        app(ActionRegistry::class)->register($action);
        $this->artisan('agent-access:validate')->expectsOutputToContain('Invalid action input mapping')->assertFailed();
    }
}
