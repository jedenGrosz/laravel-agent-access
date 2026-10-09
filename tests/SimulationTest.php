<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use LaravelAgentAccess\Adapters\Mcp\Tools\SimulateGrant;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Authorization\GrantSimulationService;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Rules\GrantRule;
use LaravelAgentAccess\Rules\RoleHierarchy;
use LaravelAgentAccess\Rules\RuleRegistry;

final class SimulationTest extends TestCase
{
    private function simulate(array $override = []): array
    {
        return app(GrantSimulationService::class)->simulate($this->context($this->manager), 'orders.refund', $this->args(),
            'finance-refund-support', (string) $this->support->id, ...$override);
    }

    public function test_simulation_is_side_effect_free_and_uses_same_policy_as_real_approval(): void
    {
        $simulation = $this->simulate();
        self::assertTrue($simulation['allowed']);
        self::assertSame('GRANT_ALLOWED', $simulation['reason_code']);
        self::assertSame('MISSING_PERMISSION', $simulation['baseline']);
        self::assertSame(0, AuditEvent::query()->count());
        self::assertSame(0, DB::table('agent_access_requests')->count());
        self::assertSame(0, Grant::query()->count());
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        self::assertNotNull(app(ApprovalService::class)->approve($this->context($this->manager), $request->id));
    }

    public function test_constraints_explain_precise_denial_without_clamping(): void
    {
        $long = $this->simulate(['durationSeconds' => 901]);
        self::assertFalse($long['allowed']);
        self::assertSame('DURATION_EXCEEDED', $long['reason_code']);
        self::assertSame('duration', Arr::last($long['grant_authority']['checks'])['check']);
        $uses = $this->simulate(['maxUses' => 2]);
        self::assertSame('USES_EXCEEDED', $uses['reason_code']);
        self::assertSame(2, $uses['constraints']['max_uses']);
        self::assertSame('USES_EXCEEDED', $this->simulate(['maxUses' => null])['reason_code']);
    }

    public function test_hard_denial_overrides_a_rule_that_otherwise_allows_grant(): void
    {
        DB::table('orders')->where('id', 431)->update(['is_refunded' => true]);
        $result = $this->simulate();
        self::assertFalse($result['allowed']);
        self::assertSame('FORBIDDEN', $result['reason_code']);
        self::assertTrue($result['grant_authority']['allowed']);
    }

    public function test_self_approval_and_business_condition_have_safe_explanations(): void
    {
        $self = app(GrantSimulationService::class)->simulate($this->context(), 'orders.refund', $this->args(), 'finance-refund-support');
        self::assertSame('SELF_APPROVAL_FORBIDDEN', $self['reason_code']);
        self::assertTrue($self['request_eligibility']['allowed']);
        $this->manager->update(['department' => 'another']);
        Gate::define('agent_access.inspect', fn () => true);
        $condition = $this->simulate();
        self::assertSame('CONDITION_NOT_MET', $condition['reason_code']);
        self::assertSame('grantor_condition:1', Arr::last($condition['grant_authority']['checks'])['check']);
        self::assertStringNotContainsString('SameDepartment', json_encode($condition));
        self::assertStringNotContainsString($this->support->email, json_encode($condition));
    }

    public function test_other_recipient_is_hidden_before_lookup_or_resource_resolution(): void
    {
        try {
            app(GrantSimulationService::class)->simulate($this->context(), 'unknown.action', [], 'unknown.rule', 'missing-user');
            self::fail();
        } catch (AccessException $exception) {
            self::assertSame('SIMULATION_NOT_FOUND', $exception->reasonCode);
        }
        Gate::define('agent_access.inspect', fn () => false);
        $this->expectException(AccessException::class);
        $this->simulate();
    }

    public function test_http_mcp_and_artisan_simulation_obey_the_same_inspection_authority(): void
    {
        $input = ['action' => 'orders.refund', 'arguments' => $this->args(), 'rule_id' => 'finance-refund-support', 'recipient_id' => (string) $this->support->id];
        Sanctum::actingAs($this->manager);
        $this->postJson('/agent-access/v1/simulate', $input)->assertOk()->assertJsonPath('allowed', true);
        $this->actingAs($this->manager);
        $mcp = (new SimulateGrant)->handle(new Request($input));
        self::assertTrue(json_decode((string) $mcp->responses()->first()->content(), true)['allowed']);
        $this->artisan('agent-access:simulate', ['--as' => (string) $this->manager->id, '--input' => json_encode($input)])->assertSuccessful();
        Sanctum::actingAs($this->support);
        $input['recipient_id'] = (string) $this->manager->id;
        $this->postJson('/agent-access/v1/simulate', $input)->assertNotFound();
        self::assertSame(0, Grant::query()->count());
    }

    public function test_ranked_options_never_exclude_grantors_outside_hierarchy(): void
    {
        app()->instance(RoleHierarchy::class, new RoleHierarchy([['admin', 'finance_manager'], ['finance_manager', 'support']]));
        app()->instance(RuleRegistry::class, new RuleRegistry([GrantRule::named('ranked')->forPermission('orders.refund')
            ->grantableBy('outside', 'admin', 'finance_manager')->toRole('support')->bindArguments(['amount_minor', 'currency'])]));
        $options = app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->grantOptions;
        self::assertSame(['finance_manager', 'admin', 'outside'], $options[0]['eligible_grantor_roles']);
        self::assertSame([1, 2, null], array_column($options[0]['grantor_hierarchy'], 'distance'));
    }
}
