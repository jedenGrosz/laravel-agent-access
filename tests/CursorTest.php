<?php

declare(strict_types=1);

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use LaravelAgentAccess\Adapters\Mcp\Tools\ListRequests;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requests\QueryService;

final class CursorTest extends TestCase
{
    public function test_keyset_pages_have_no_duplicates_or_global_totals(): void
    {
        $expected = [];
        for ($i = 0; $i < 5; $i++) {
            $expected[] = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args())->id;
        }
        $cursor = null;
        $ids = [];
        do {
            $page = app(QueryService::class)->requestsCursor($this->context(), 2, $cursor);
            self::assertArrayNotHasKey('total', $page);
            $ids = array_merge($ids, array_column($page['data'], 'id'));
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        self::assertSame($expected, $ids);
    }

    public function test_cursor_cannot_be_replayed_by_another_user_or_for_other_filters_or_lists(): void
    {
        for ($i = 0; $i < 3; $i++) {
            app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        }
        $cursor = app(QueryService::class)->requestsCursor($this->context(), 1, status: 'pending')['next_cursor'];
        foreach ([fn () => app(QueryService::class)->requestsCursor($this->context($this->manager), 1, $cursor, 'pending'),
            fn () => app(QueryService::class)->requestsCursor($this->context(), 1, $cursor, 'approved'),
            fn () => app(QueryService::class)->grantsCursor($this->context(), 1, $cursor),
            fn () => app(QueryService::class)->requestsCursor($this->context(), 1, 'tampered', 'pending')] as $call) {
            try {
                $call();
                self::fail('A changed cursor audience/filter must be rejected.');
            } catch (AccessException $error) {
                self::assertSame('INVALID_CURSOR', $error->reasonCode);
            }
        }
    }

    public function test_bounded_scan_does_not_emit_hidden_ids_and_rechecks_role_loss(): void
    {
        config(['agent-access.queries.cursor_scan_limit' => 2]);
        $requests = [];
        for ($i = 0; $i < 4; $i++) {
            $requests[] = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args())->id;
        }
        $first = app(QueryService::class)->requestsCursor($this->context($this->manager), 1);
        self::assertCount(1, $first['data']);
        $this->manager->update(['department' => 'another']);
        $hidden = app(QueryService::class)->requestsCursor($this->context($this->manager), 1, $first['next_cursor']);
        self::assertSame([], $hidden['data']);
        self::assertNotNull($hidden['next_cursor']);
        foreach ($requests as $id) {
            self::assertStringNotContainsString($id, json_encode($hidden));
        }
        $this->manager->removeRole('finance_manager');
        self::assertSame([], app(QueryService::class)->requestsCursor($this->context($this->manager), 1, $hidden['next_cursor'])['data']);
    }

    public function test_http_mcp_and_artisan_share_cursor_mode(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        Sanctum::actingAs($this->support);
        $this->getJson('/agent-access/v1/requests?pagination=cursor&per_page=1')->assertOk()->assertJsonPath('data.0.id', $request->id)->assertJsonMissingPath('total');
        $response = (new ListRequests)->handle(new Request(['pagination' => 'cursor', 'per_page' => 1]));
        self::assertSame($request->id, json_decode((string) $response->responses()->first()->content(), true)['data'][0]['id']);
        $this->artisan('agent-access:requests', ['--as' => (string) $this->support->id, '--input' => '{"pagination":"cursor"}'])->assertSuccessful();
    }

    public function test_large_history_is_read_in_a_bounded_sql_window(): void
    {
        config(['agent-access.queries.cursor_scan_limit' => 40]);
        $template = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args())->getAttributes();
        for ($batch = 0; $batch < 20; $batch++) {
            $rows = [];
            for ($row = 0; $row < 100; $row++) {
                $rows[] = array_replace($template, ['id' => strtolower((string) Str::ulid())]);
            }
            DB::table('agent_access_requests')->insert($rows);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = app(QueryService::class)->requestsCursor($this->context(), 10);
            $reads = array_values(array_filter(DB::getQueryLog(), fn (array $entry) => str_starts_with($entry['query'], 'select') && str_contains($entry['query'], 'agent_access_requests')));
        } finally {
            DB::disableQueryLog();
        }
        self::assertCount(10, $page['data']);
        self::assertNotNull($page['next_cursor']);
        self::assertCount(1, $reads);
        self::assertStringContainsString('limit 40', $reads[0]['query']);
        self::assertArrayNotHasKey('total', $page);
    }

    public function test_cursor_expires_exactly_at_its_one_hour_boundary(): void
    {
        $issued = CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC');
        CarbonImmutable::setTestNow($issued);
        try {
            app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
            $second = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
            $cursor = app(QueryService::class)->requestsCursor($this->context(), 1)['next_cursor'];
            self::assertNotNull($cursor);
            CarbonImmutable::setTestNow($issued->addHour()->subMicrosecond());
            $page = app(QueryService::class)->requestsCursor($this->context(), 1, $cursor);
            self::assertSame($second->id, $page['data'][0]['id']);
            CarbonImmutable::setTestNow($issued->addHour());
            $this->expectException(AccessException::class);
            $this->expectExceptionMessage('INVALID_CURSOR');
            app(QueryService::class)->requestsCursor($this->context(), 1, $cursor);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_missing_application_key_is_not_reported_as_an_invalid_client_cursor(): void
    {
        for ($i = 0; $i < 2; $i++) {
            app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        }
        $cursor = app(QueryService::class)->requestsCursor($this->context(), 1)['next_cursor'];
        $key = config('app.key');
        config(['app.key' => null]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        try {
            $this->expectException(MissingAppKeyException::class);
            app(QueryService::class)->requestsCursor($this->context(), 1, $cursor);
        } finally {
            config(['app.key' => $key]);
            $this->app->forgetInstance('encrypter');
            Crypt::clearResolvedInstance('encrypter');
        }
    }

    public function test_decrypted_malformed_cursor_json_is_rejected(): void
    {
        $cursor = Crypt::encryptString('{');
        $this->expectException(AccessException::class);
        $this->expectExceptionMessage('INVALID_CURSOR');
        app(QueryService::class)->requestsCursor($this->context(), 1, $cursor);
    }

    public function test_oversized_php_cursors_are_rejected_without_decryption(): void
    {
        Crypt::shouldReceive('decryptString')->never();
        $service = app(QueryService::class);
        foreach (['requestsCursor', 'grantsCursor'] as $method) {
            try {
                $service->{$method}($this->context(), 1, str_repeat('x', 4097));
                self::fail('Oversized cursors must be rejected.');
            } catch (AccessException $exception) {
                self::assertSame('INVALID_CURSOR', $exception->reasonCode);
                self::assertSame(422, $exception->status);
            }
        }
    }

    public function test_cursor_at_the_size_limit_still_reaches_decryption(): void
    {
        $cursor = str_repeat('x', 4096);
        Crypt::shouldReceive('decryptString')->once()->with($cursor)->andThrow(new DecryptException);
        $this->expectException(AccessException::class);
        $this->expectExceptionMessage('INVALID_CURSOR');
        app(QueryService::class)->requestsCursor($this->context(), 1, $cursor);
    }
}
