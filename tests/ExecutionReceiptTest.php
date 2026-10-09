<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use LaravelAgentAccess\Actions\Action;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Adapters\Mcp\Tools\RunAction;
use LaravelAgentAccess\Adapters\Mcp\Tools\ShowExecution;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Audit\DatabaseAuditWriter;
use LaravelAgentAccess\Audit\ExecutionReceiptService;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;

final class ExecutionReceiptTest extends TestCase
{
    private function issue(): Grant
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());

        return app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
    }

    public function test_successful_receipt_exposes_only_safe_evidence_and_never_reexecutes(): void
    {
        $grant = $this->issue();
        $result = app(ActionExecutor::class)->executeWithReceipt($this->context(), 'orders.refund', $this->args());
        self::assertTrue($result->result['refunded']);
        $receipt = app(ExecutionReceiptService::class)->show($this->context(), $result->executionId);
        self::assertSame('SUCCEEDED', $receipt['status']);
        self::assertTrue($receipt['admitted']);
        self::assertFalse($receipt['requires_review']);
        self::assertSame('orders.refund', $receipt['action']);
        self::assertSame(['type' => 'order', 'id' => '431'], $receipt['resource']);
        self::assertArrayNotHasKey('result', $receipt);
        self::assertStringNotContainsString('amount_minor', json_encode($receipt));
        app(ExecutionReceiptService::class)->show($this->context(), $result->executionId);
        self::assertSame(1, $grant->fresh()->uses);
        self::assertSame(1, DB::table('refunds')->count());
    }

    public function test_result_audit_failure_is_readable_as_unknown_and_remains_consumed(): void
    {
        $grant = $this->issue();
        app()->bind(AuditWriter::class, fn () => new class implements AuditWriter
        {
            public function record(string $event, ExecutionContext $context, array $references = []): void
            {
                if ($event === 'ACTION_SUCCEEDED') {
                    throw new \RuntimeException('Audit unavailable.');
                }
                (new DatabaseAuditWriter)->record($event, $context, $references);
            }
        });
        try {
            app(ActionExecutor::class)->executeWithReceipt($this->context(), 'orders.refund', $this->args());
            self::fail();
        } catch (AccessException $error) {
            self::assertSame('ACTION_RESULT_UNRECORDED', $error->reasonCode);
            $receipt = app(ExecutionReceiptService::class)->show($this->context(), $error->executionId);
            self::assertSame('OUTCOME_UNKNOWN', $receipt['status']);
            self::assertTrue($receipt['requires_review']);
        }
        self::assertSame(1, $grant->fresh()->uses);
        self::assertSame(1, DB::table('refunds')->count());
    }

    public function test_denial_has_a_receipt_without_admission_and_cannot_bypass_iam(): void
    {
        try {
            app(ActionExecutor::class)->executeWithReceipt($this->context(), 'orders.refund', $this->args());
            self::fail();
        } catch (AccessException $error) {
            self::assertArrayHasKey('execution_id', $error->toArray());
            $receipt = app(ExecutionReceiptService::class)->show($this->context(), $error->executionId);
            self::assertSame('DENIED', $receipt['status']);
            self::assertFalse($receipt['admitted']);
        }
        self::assertSame(0, DB::table('refunds')->count());
    }

    public function test_failed_handler_can_be_found_without_replaying_it(): void
    {
        $grant = $this->issue();
        DB::table('refunds')->insert(['order_id' => 431, 'amount_minor' => 100, 'currency' => 'PLN']);
        $context = $this->context();
        try {
            app(ActionExecutor::class)->executeWithReceipt($context, 'orders.refund', $this->args());
            self::fail();
        } catch (QueryException) {
        }
        $receipts = app(ExecutionReceiptService::class)->list($context, correlationId: $context->correlationId);
        self::assertSame(1, $receipts['total']);
        self::assertSame('FAILED', $receipts['data'][0]['status']);
        self::assertSame(1, $grant->fresh()->uses);
        self::assertSame(1, DB::table('refunds')->count());
    }

    public function test_receipt_envelope_preserves_business_result_keys_and_legacy_execute_shape(): void
    {
        $action = new #[RequiresPermission('fixture.collision', ability: 'fixture.allowed')] class extends Action
        {
            public function key(): string
            {
                return 'fixture.collision';
            }

            public function rules(): array
            {
                return [];
            }

            public function handle(ExecutionContext $context, AccessRequirement $requirement): array
            {
                return ['execution_id' => 'business-id', 'correlation_id' => 'business-correlation'];
            }
        };
        app(ActionRegistry::class)->register($action);
        Gate::define('fixture.allowed', fn () => true);
        $legacy = app(ActionExecutor::class)->execute($this->context(), $action->key(), []);
        self::assertSame(['execution_id' => 'business-id', 'correlation_id' => 'business-correlation'], $legacy);
        $envelope = app(ActionExecutor::class)->executeWithReceipt($this->context(), $action->key(), [])->toArray();
        self::assertSame($legacy, $envelope['result']);
        self::assertNotSame('business-id', $envelope['execution_id']);
    }

    public function test_cross_user_receipt_requires_explicit_current_inspection_authority(): void
    {
        $result = app(ActionExecutor::class)->executeWithReceipt($this->context(), 'orders.view', ['order_id' => 431]);
        self::assertSame('SUCCEEDED', app(ExecutionReceiptService::class)->show($this->context($this->manager), $result->executionId)['status']);
        Gate::define('agent_access.inspect', fn () => false);
        $this->expectException(AccessException::class);
        app(ExecutionReceiptService::class)->show($this->context($this->manager), $result->executionId);
    }

    public function test_unknown_or_other_identity_realm_receipt_is_hidden(): void
    {
        $result = app(ActionExecutor::class)->executeWithReceipt($this->context(), 'orders.view', ['order_id' => 431]);
        AuditEvent::query()->where('execution_id', $result->executionId)->update(['actor_type' => 'another-realm']);
        $this->expectException(AccessException::class);
        app(ExecutionReceiptService::class)->show($this->context(), $result->executionId);
    }

    public function test_http_mcp_and_artisan_return_and_read_receipts_through_shared_services(): void
    {
        Sanctum::actingAs($this->support);
        $id = $this->postJson('/agent-access/v1/actions/orders.view/run', ['arguments' => ['order_id' => 431]])
            ->assertOk()->assertJsonPath('code', 'ACTION_EXECUTED')->json('execution_id');
        $this->getJson('/agent-access/v1/executions/'.$id)->assertOk()->assertJsonPath('status', 'SUCCEEDED');
        $this->actingAs($this->support);
        $run = (new RunAction)->handle(new Request(['action' => 'orders.view', 'arguments' => ['order_id' => 431]]));
        $runId = json_decode((string) $run->responses()->first()->content(), true)['execution_id'];
        $read = (new ShowExecution)->handle(new Request(['id' => $runId]));
        self::assertSame('SUCCEEDED', json_decode((string) $read->responses()->first()->content(), true)['status']);
        $this->artisan('agent-access:execution', ['--as' => (string) $this->support->id, '--input' => json_encode(['id' => $id])])->assertSuccessful();
        $this->getJson('/agent-access/v1/executions?per_page=1')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_execution_list_is_owned_and_correlation_filter_cannot_reveal_other_users_attempts(): void
    {
        $context = $this->context();
        app(ActionExecutor::class)->executeWithReceipt($context, 'orders.view', ['order_id' => 431]);
        $this->manager->givePermissionTo('orders.view');
        $other = app(ActionExecutor::class)->executeWithReceipt($this->context($this->manager), 'orders.view', ['order_id' => 431]);
        self::assertSame(1, app(ExecutionReceiptService::class)->list($context)['total']);
        self::assertSame(0, app(ExecutionReceiptService::class)->list($context, correlationId: $other->correlationId)['total']);
        self::assertSame(1, app(ExecutionReceiptService::class)->list($context, correlationId: $context->correlationId)['total']);
    }
}
