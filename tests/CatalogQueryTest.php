<?php

declare(strict_types=1);

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use LaravelAgentAccess\Actions\ActionCatalog;
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Adapters\Mcp\Tools\ListActions;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requests\QueryService;
use LaravelAgentAccess\Rules\GrantRule;
use LaravelAgentAccess\Rules\RuleRegistry;
use Workbench\App\Models\User;

final class CatalogQueryTest extends TestCase
{
    public function test_catalog_is_authenticated_metadata_and_does_not_authorize_or_write(): void
    {
        $catalog = app(ActionCatalog::class)->list($this->context());
        self::assertSame(['orders.cancel', 'orders.delete', 'orders.refund', 'orders.view'], array_column($catalog['data'], 'action'));
        $refund = $catalog['data'][2];
        self::assertSame(['order_id', 'amount_minor', 'currency'], $refund['argument_names']);
        self::assertSame('finance-refund-support', $refund['candidate_grant_rules'][0]['rule_id']);
        self::assertArrayNotHasKey('allowed', $refund);
        self::assertArrayNotHasKey('requestable', $refund);
        self::assertStringNotContainsString('Workbench', json_encode($catalog));
        self::assertSame(0, AuditEvent::query()->count());
        $this->getJson('/agent-access/v1/actions')->assertUnauthorized();
        Sanctum::actingAs($this->support);
        $this->getJson('/agent-access/v1/actions')->assertOk()->assertJsonPath('code', 'ACTION_CATALOG');
        $this->actingAs($this->support);
        $mcp = (new ListActions)->handle(new Request([]));
        self::assertSame($catalog['data'], json_decode((string) $mcp->responses()->first()->content(), true)['data']);
        $this->artisan('agent-access:actions', ['--as' => (string) $this->support->id])->assertSuccessful();
        $this->artisan('agent-access:requests', ['--as' => (string) $this->support->id])->assertSuccessful();
        $this->artisan('agent-access:grants', ['--as' => (string) $this->support->id])->assertSuccessful();
        self::assertSame(0, AuditEvent::query()->count());
    }

    public function test_discovery_filters_candidate_rules_by_recipient_roles_without_promising_access(): void
    {
        $catalog = app(ActionCatalog::class)->list($this->context($this->manager));
        self::assertSame([], $catalog['data'][2]['candidate_grant_rules']);
        self::assertCount(4, $catalog['data']);
    }

    public function test_lookup_index_migration_can_rollback_and_upgrade_without_losing_grants(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $grant = app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        self::assertTrue(Schema::hasIndex('agent_access_grants', 'laa_grant_resource'));
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
        self::assertFalse(Schema::hasIndex('agent_access_grants', 'laa_grant_resource'));
        self::assertSame($grant->id, $grant->fresh()->id);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        self::assertTrue(Schema::hasIndex('agent_access_grants', 'laa_grant_resource'));
        self::assertTrue(app(ActionExecutor::class)->execute($this->context(), 'orders.refund', $this->args())['refunded']);
    }

    public function test_reader_with_no_management_roles_does_not_resolve_hidden_resources(): void
    {
        for ($index = 0; $index < 20; $index++) {
            app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), reason: 'request '.$index);
        }
        $outsider = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'unused']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(QueryService::class)->requests($this->context($outsider));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame(0, $result['total']);
        self::assertSame([], $result['data']);
        self::assertSame([], array_values(array_filter($queries, fn (array $query) => str_contains($query['query'], 'orders'))));
    }

    public function test_sql_candidates_still_require_business_visibility_before_totals(): void
    {
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->manager->update(['department' => 'another']);
        self::assertSame(0, app(QueryService::class)->requests($this->context($this->manager))['total']);
        self::assertSame(1, app(QueryService::class)->requests($this->context())['total']);
    }

    public function test_role_loss_and_changed_rule_remove_other_users_records_but_preserve_ownership(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        self::assertSame(1, app(QueryService::class)->grants($this->context($this->manager))['total']);
        $this->manager->removeRole('finance_manager');
        self::assertSame(0, app(QueryService::class)->grants($this->context($this->manager))['total']);
        self::assertSame(1, app(QueryService::class)->grants($this->context())['total']);
        $this->manager->assignRole('finance_manager');
        app()->instance(RuleRegistry::class, new RuleRegistry([GrantRule::named('finance-refund-support')->forPermission('orders.refund')
            ->grantableBy('finance_manager')->toRole('support')->version('changed')]));
        self::assertSame(0, app(QueryService::class)->requests($this->context($this->manager))['total']);
        self::assertSame(0, app(QueryService::class)->grants($this->context($this->manager))['total']);
        self::assertSame(1, app(QueryService::class)->requests($this->context())['total']);
    }

    public function test_status_filter_uses_clock_boundary_and_paginates_only_visible_records(): void
    {
        $requests = [];
        for ($index = 0; $index < 3; $index++) {
            $requests[] = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        }
        $requests[0]->update(['expires_at' => CarbonImmutable::now('UTC')->subSecond()]);
        $pending = app(QueryService::class)->requests($this->context(), perPage: 1, status: 'pending');
        self::assertSame(2, $pending['total']);
        self::assertCount(1, $pending['data']);
        $expired = app(QueryService::class)->requests($this->context(), status: 'expired');
        self::assertSame(1, $expired['total']);
        self::assertSame('expired', $expired['data'][0]['status']);
    }
}
