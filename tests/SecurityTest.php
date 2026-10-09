<?php

declare(strict_types=1);

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Audit\DatabaseAuditWriter;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Authorization\IamDecision;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Contracts\PrincipalResolver;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\ExpirationService;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ContextFactory;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Models\GrantUse;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requests\QueryService;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Rules\GrantRule;
use LaravelAgentAccess\Rules\RoleHierarchy;
use LaravelAgentAccess\Rules\RuleRegistry;
use Spatie\Permission\Models\Role;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

final class SecurityTest extends TestCase
{
    public function test_another_authenticatable_model_cannot_borrow_a_user_identity(): void
    {
        config(['agent-access.principal_model' => \Illuminate\Foundation\Auth\User::class]);
        $foreign = new class extends \Illuminate\Foundation\Auth\User {};
        $foreign->setRawAttributes(['id' => $this->support->id]);
        $this->expectException(AuthenticationException::class);
        app(PrincipalResolver::class)->resolve($foreign);
    }

    public function test_role_from_another_guard_cannot_approve(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->manager->removeRole('finance_manager');
        $apiRole = Role::findOrCreate('finance_manager', 'api');
        $this->manager->roles()->attach($apiRole);
        $this->denied(fn () => app(ApprovalService::class)->approve($this->context($this->manager), $request->id), 'REQUEST_NOT_FOUND');
        self::assertSame(0, Grant::query()->count());
    }

    public function test_result_audit_failure_returns_receipt_without_reexecuting(): void
    {
        $grant = $this->issue();
        app()->bind(AuditWriter::class, fn () => new class implements AuditWriter
        {
            public function record(string $event, ExecutionContext $context, array $references = []): void
            {
                if ($event === 'ACTION_SUCCEEDED') {
                    throw new \RuntimeException('Audit offline.');
                }
                (new DatabaseAuditWriter)->record($event, $context, $references);
            }
        });
        try {
            app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args());
            self::fail();
        } catch (AccessException $e) {
            self::assertSame('ACTION_RESULT_UNRECORDED', $e->reasonCode);
            self::assertNotNull($e->executionId);
            self::assertArrayHasKey('execution_id', $e->toArray());
        }
        self::assertSame(1, DB::table('refunds')->count());
        self::assertSame(1, $grant->fresh()->uses);
        self::assertSame(1, AuditEvent::query()->where('event_type', 'ACTION_STARTED')->count());
        self::assertSame(0, AuditEvent::query()->where('event_type', 'ACTION_SUCCEEDED')->count());
    }

    public function test_client_metadata_is_not_authority_and_bearer_is_not_audited(): void
    {
        $token = $this->support->createToken('test');
        $this->support->withAccessToken($token->accessToken);
        $context = app(ContextFactory::class)->authenticated($this->support, 'http', ['client_id' => 'claimed-manager', 'roles' => ['finance_manager']]);
        self::assertSame('token:'.$token->accessToken->getKey(), $context->trustedMetadata['session_id']);
        self::assertSame(['client_id' => 'claimed-manager'], $context->declaredMetadata);
        self::assertFalse(app(DecisionService::class)->check($context, 'orders.refund', $this->args())->allowed);
        $json = json_encode(AuditEvent::query()->get()->toArray());
        self::assertStringNotContainsString($token->plainTextToken, $json);
    }

    public function test_grants_and_audit_stay_in_utc_with_non_utc_host_timezone(): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Warsaw');
            $grant = $this->issue()->fresh();
            self::assertSame('UTC', $grant->valid_from->timezoneName);
            self::assertTrue(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
            self::assertSame('UTC', AuditEvent::query()->first()->created_at->timezoneName);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function issue(): Grant
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());

        return app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
    }

    private function denied(callable $operation, ?string $code = null): void
    {
        try {
            $operation();
            self::fail('Operation was unexpectedly allowed.');
        } catch (AccessException $e) {
            if ($code !== null) {
                self::assertSame($code, $e->reasonCode);
            }
        }
    }

    public function test_plain_gate_denial_is_not_requestable(): void
    {
        Gate::before(fn () => false);
        $decision = app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args());
        self::assertSame('FORBIDDEN', $decision->reasonCode);
        self::assertSame([], $decision->grantOptions);
        $this->denied(fn () => app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args()));
        self::assertSame(0, AccessRequest::query()->count());
    }

    public function test_baseline_access_does_not_consume_existing_grant(): void
    {
        $grant = $this->issue();
        $this->support->givePermissionTo('orders.refund');
        app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args());
        self::assertSame(0, $grant->fresh()->uses);
    }

    public function test_changed_currency_other_user_and_resource_do_not_match(): void
    {
        $this->issue();
        Order::query()->create(['id' => 432, 'amount_minor' => 20000, 'currency' => 'PLN']);
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args(432))->allowed);
        self::assertFalse(app(DecisionService::class)->check($this->context($this->manager), 'orders.refund', $this->args())->allowed);
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args() + ['extra' => 'ignored'])->requirement->arguments['currency'] !== 'PLN');
        $args = $this->args();
        $args['currency'] = 'EUR';
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $args)->allowed);
    }

    public function test_expiry_boundary_and_future_valid_from(): void
    {
        $grant = $this->issue()->fresh();
        CarbonImmutable::setTestNow($grant->expires_at);
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
        CarbonImmutable::setTestNow($grant->valid_from->subSecond());
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
    }

    public function test_revocation_is_idempotent_and_blocks_use(): void
    {
        $grant = $this->issue();
        app(GrantService::class)->revoke($this->context(), $grant->id);
        app(GrantService::class)->revoke($this->context(), $grant->id);
        self::assertSame(1, AuditEvent::query()->where('event_type', 'GRANT_REVOKED')->count());
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
    }

    public function test_rule_change_invalidates_request_and_grant(): void
    {
        $grant = $this->issue();
        $pending = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(amount: 11000));
        app()->instance(RuleRegistry::class, new RuleRegistry([GrantRule::named('finance-refund-support')->forPermission('orders.refund')
            ->grantableBy('finance_manager')->toRole('support')->maxDuration(300)->bindArguments(['amount_minor', 'currency'])]));
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
        $this->denied(fn () => app(ApprovalService::class)->approve($this->context($this->manager), $pending->id));
        self::assertSame(0, $grant->fresh()->uses);
    }

    public function test_no_self_approval_even_when_user_has_grantor_role(): void
    {
        $this->support->assignRole('finance_manager');
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->denied(fn () => app(ApprovalService::class)->approve($this->context(), $request->id));
        self::assertSame(0, Grant::query()->count());
    }

    public function test_hierarchy_is_advisory_and_department_condition_is_enforced(): void
    {
        app()->instance(RoleHierarchy::class, new RoleHierarchy([['administrator', 'support']]));
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->manager->department = 'other';
        $this->manager->save();
        $this->denied(fn () => app(ApprovalService::class)->approve($this->context($this->manager), $request->id));
        $this->manager->department = 'finance';
        $this->manager->save();
        self::assertInstanceOf(Grant::class, app(ApprovalService::class)->approve($this->context($this->manager), $request->id));
    }

    public function test_cannot_combine_limits_from_two_rules(): void
    {
        app()->instance(RuleRegistry::class, new RuleRegistry([
            GrantRule::named('short-many')->forPermission('orders.refund')->grantableBy('finance_manager')->toRole('support')->maxDuration(60)->maxUses(10),
            GrantRule::named('long-once')->forPermission('orders.refund')->grantableBy('finance_manager')->toRole('support')->maxDuration(900)->maxUses(1),
        ]));
        $this->denied(fn () => app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), 'short-many', 900, 10), 'INVALID_CONSTRAINTS');
        $this->denied(fn () => app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), 'long-once', 900, 10), 'INVALID_CONSTRAINTS');
        self::assertSame(0, AccessRequest::query()->count());
    }

    public function test_cancel_reject_expiry_and_idempotent_approval(): void
    {
        $service = app(AccessRequestService::class);
        $approvals = app(ApprovalService::class);
        $cancelled = $service->create($this->context(), 'orders.refund', $this->args());
        $service->cancel($this->context(), $cancelled->id);
        $this->denied(fn () => $approvals->approve($this->context($this->manager), $cancelled->id), 'REQUEST_STATE_CONFLICT');
        $rejected = $service->create($this->context(), 'orders.refund', $this->args());
        $approvals->reject($this->context($this->manager), $rejected->id);
        $this->denied(fn () => $approvals->approve($this->context($this->manager), $rejected->id), 'REQUEST_STATE_CONFLICT');
        $expired = $service->create($this->context(), 'orders.refund', $this->args());
        $expired->expires_at = CarbonImmutable::now('UTC')->subSecond();
        $expired->save();
        $this->denied(fn () => $approvals->approve($this->context($this->manager), $expired->id), 'REQUEST_STATE_CONFLICT');
        self::assertSame('expired', $expired->fresh()->status);
        $approved = $service->create($this->context(), 'orders.refund', $this->args());
        $a = $approvals->approve($this->context($this->manager), $approved->id);
        $b = $approvals->approve($this->context($this->manager), $approved->id);
        self::assertSame($a->id, $b->id);
        self::assertSame(1, Grant::query()->count());
    }

    public function test_creation_idempotency_conflict_and_actor_scope(): void
    {
        $a = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), idempotencyKey: 'retry-1');
        $b = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), idempotencyKey: 'retry-1');
        self::assertSame($a->id, $b->id);
        $this->denied(fn () => app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(amount: 11000), idempotencyKey: 'retry-1'), 'IDEMPOTENCY_CONFLICT');
        self::assertSame(1, AccessRequest::query()->count());
    }

    public function test_hidden_records_and_totals_do_not_leak(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $outsider = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'unused']);
        $this->denied(fn () => app(AccessRequestService::class)->show($this->context($outsider), $request->id), 'REQUEST_NOT_FOUND');
        self::assertSame(0, app(QueryService::class)->requests($this->context($outsider))['total']);
        self::assertSame(1, app(QueryService::class)->requests($this->context($this->manager))['total']);
    }

    public function test_failure_keeps_grant_consumed(): void
    {
        $grant = $this->issue();
        DB::table('refunds')->insert(['order_id' => 431, 'amount_minor' => 100, 'currency' => 'PLN']);
        try {
            app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args());
            self::fail('Duplicate refund should fail.');
        } catch (QueryException) {
        }
        self::assertSame(1, $grant->fresh()->uses);
        self::assertSame(1, GrantUse::query()->count());
        self::assertFalse(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->allowed);
        self::assertSame(1, AuditEvent::query()->where('event_type', 'ACTION_FAILED')->count());
    }

    public function test_audit_failure_rolls_back_approval_and_admission(): void
    {
        $pending = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $grant = app(ApprovalService::class)->approve($this->context($this->manager), $pending->id);
        $other = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(amount: 11000));
        app()->bind(AuditWriter::class, fn () => new class implements AuditWriter
        {
            public function record(string $event, ExecutionContext $context, array $references = []): void
            {
                throw new \RuntimeException('Audit offline.');
            }
        });
        try {
            app(ApprovalService::class)->approve($this->context($this->manager), $other->id);
            self::fail();
        } catch (\RuntimeException) {
        }
        self::assertSame('pending', $other->fresh()->status);
        self::assertSame(1, Grant::query()->count());
        try {
            app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args());
            self::fail();
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $grant->fresh()->uses);
        self::assertSame(0, DB::table('refunds')->count());
    }

    public function test_iam_error_does_not_run_handler_or_create_request(): void
    {
        app()->bind(IamAdapter::class, fn () => new class implements IamAdapter
        {
            public function evaluate(Principal $principal, AccessRequirement $requirement): IamDecision
            {
                throw new \RuntimeException('IAM offline.');
            }
        });
        try {
            app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args());
            self::fail();
        } catch (\RuntimeException) {
        }
        self::assertSame(0, DB::table('refunds')->count());
        try {
            app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
            self::fail();
        } catch (\RuntimeException) {
        }
        self::assertSame(0, AccessRequest::query()->count());
    }

    public function test_audit_is_allowlisted_and_expiration_is_idempotent(): void
    {
        $grant = $this->issue();
        app(AuditWriter::class)->record('TEST', $this->context(), ['token' => 'secret-token', 'password' => 'secret-password', 'Authorization' => 'secret-header']);
        self::assertStringNotContainsString('secret-', json_encode(AuditEvent::query()->get()->toArray()));
        CarbonImmutable::setTestNow($grant->expires_at->addSecond());
        app(ExpirationService::class)->run($this->context($this->manager));
        app(ExpirationService::class)->run($this->context($this->manager));
        self::assertSame(1, AuditEvent::query()->where('event_type', 'GRANT_EXPIRED')->count());
    }

    public function test_invalid_hierarchy_and_configuration_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RoleHierarchy([['a', 'b'], ['b', 'a']]);
    }
}
