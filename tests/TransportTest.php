<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use LaravelAgentAccess\Adapters\Mcp\Tools\ApproveRequest;
use LaravelAgentAccess\Adapters\Mcp\Tools\CheckAccess;
use LaravelAgentAccess\Adapters\Mcp\Tools\RequestAccess;
use LaravelAgentAccess\Authorization\DecisionService;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;
use Workbench\App\Mcp\OrdersServer;
use Workbench\App\Mcp\RefundTool;

final class TransportTest extends TestCase
{
    public function test_real_mcp_serialization_preserves_error_and_structured_content(): void
    {
        OrdersServer::actingAs($this->support)->tool(RefundTool::class, $this->args())
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('code', 'ACCESS_REQUIRED')->where('allowed', false)->where('requestable', true)->etc());
    }

    public function test_http_complete_refund_and_identity_cannot_be_overridden(): void
    {
        Sanctum::actingAs($this->support);
        $input = ['action' => 'orders.refund', 'arguments' => $this->args()];
        $this->postJson('/agent-access/v1/check', $input)->assertOk()->assertJsonPath('code', 'ACCESS_REQUIRED');
        $this->postJson('/agent-access/v1/requests', $input + ['user_id' => $this->manager->id])->assertUnprocessable();
        $id = $this->postJson('/agent-access/v1/requests', $input)->assertOk()->json('id');
        $this->postJson('/agent-access/v1/requests/'.$id.'/approve')->assertNotFound();
        Sanctum::actingAs($this->manager);
        $this->getJson('/agent-access/v1/requests?status=pending')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/agent-access/v1/requests/'.$id.'/approve')->assertOk();
        Sanctum::actingAs($this->support);
        $this->postJson('/agent-access/v1/actions/orders.refund/execute', ['arguments' => $this->args()])->assertOk()->assertJsonPath('refunded', true);
        $this->postJson('/agent-access/v1/actions/orders.refund/execute', ['arguments' => $this->args()])->assertForbidden();
    }

    public function test_mcp_refund_uses_shared_services_and_structured_errors(): void
    {
        $this->actingAs($this->support);
        $denied = (new RefundTool)->handle(new Request($this->args()));
        self::assertTrue($denied->responses()->first()->isError());
        $check = (new CheckAccess)->handle(new Request(['action' => 'orders.refund', 'arguments' => $this->args()]));
        $json = json_decode((string) $check->responses()->first()->content(), true);
        self::assertSame(app(DecisionService::class)->check($this->context(), 'orders.refund', $this->args())->toArray()['grant_options'], $json['grant_options']);
        $created = (new RequestAccess)->handle(new Request(['action' => 'orders.refund', 'arguments' => $this->args()]));
        $id = json_decode((string) $created->responses()->first()->content(), true)['id'];
        $this->actingAs($this->manager);
        $approved = (new ApproveRequest)->handle(new Request(['id' => $id]));
        self::assertFalse($approved->responses()->first()->isError());
        $this->actingAs($this->support);
        $success = (new RefundTool)->handle(new Request($this->args()));
        self::assertFalse($success->responses()->first()->isError());
        self::assertSame(1, Grant::query()->first()->uses);
    }

    public function test_artisan_checks_policy_and_requires_as(): void
    {
        $this->artisan('agent-access:validate')->assertSuccessful();
        $this->artisan('agent-access:check')->assertFailed();
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->artisan('agent-access:approve', ['--as' => (string) $this->support->id, '--input' => json_encode(['id' => $request->id])])->assertFailed();
        $this->artisan('agent-access:approve', ['--as' => (string) $this->manager->id, '--input' => json_encode(['id' => $request->id])])->assertSuccessful();
        self::assertSame(1, Grant::query()->count());
    }
}
