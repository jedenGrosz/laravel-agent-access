<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Contracts\NotificationRecipients;
use LaravelAgentAccess\Events\AccessEvent;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\InstallationDoctor;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Notifications\AccessWorkflowNotification;
use LaravelAgentAccess\Notifications\NotifyAccessEvent;
use LaravelAgentAccess\Requests\AccessRequestService;
use Workbench\App\Models\User;
use Workbench\App\Notifications\WorkflowRecipients;

final class OperationsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('agent-access.panel.enabled', true);
        $app['config']->set('agent-access.notifications.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Route::get('/login', fn () => 'Host login')->name('login');
    }

    public function test_panel_requires_auth_and_only_exposes_currently_visible_requests(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), reason: '<script>privateMarker</script>');
        $this->get('/agent-access/requests')->assertRedirect('/login');
        $this->actingAs($this->manager)->get('/agent-access/requests')->assertOk()->assertSee('Open request');
        $detail = $this->get('/agent-access/requests/'.$request->id)->assertOk()->assertSee('&lt;script&gt;privateMarker&lt;/script&gt;', false)->assertDontSee('<script>privateMarker', false)->assertSee('Approve this scope');
        $preview = dirname(__DIR__).'/.test-results/panel-preview';
        if (! is_dir($preview)) {
            mkdir($preview, 0777, true);
        }
        file_put_contents($preview.'/request.html', str_replace('<form ', '<form inert ', $detail->getContent()));
        $this->manager->update(['department' => 'another']);
        $this->get('/agent-access/requests/'.$request->id)->assertNotFound();
    }

    public function test_panel_mutations_recheck_authority_and_never_self_approve(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->actingAs($this->support)->post('/agent-access/requests/'.$request->id.'/approve')->assertNotFound();
        $this->actingAs($this->manager)->get('/agent-access/requests/'.$request->id)->assertOk();
        $this->manager->removeRole('finance_manager');
        $this->post('/agent-access/requests/'.$request->id.'/approve')->assertNotFound();
        self::assertSame(0, Grant::query()->count());
        $this->manager->assignRole('finance_manager');
        $this->post('/agent-access/requests/'.$request->id.'/approve')->assertRedirect();
        self::assertSame(1, Grant::query()->count());
        $this->get('/agent-access/grants')->assertOk()->assertSee('orders.refund');
    }

    public function test_panel_forms_enforce_csrf_outside_the_test_environment(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->app->detectEnvironment(fn () => 'production');
        $this->actingAs($this->manager)->post('/agent-access/requests/'.$request->id.'/approve')->assertStatus(419);
        self::assertSame(0, Grant::query()->count());
        $this->withSession(['_token' => 'valid-csrf'])->post('/agent-access/requests/'.$request->id.'/approve', ['_token' => 'valid-csrf'])->assertRedirect();
        self::assertSame(1, Grant::query()->count());
    }

    public function test_hierarchy_view_requires_explicit_host_authority(): void
    {
        $this->actingAs($this->manager)->get('/agent-access/hierarchy')->assertNotFound();
        Gate::define('agent_access.view_configuration', fn () => true);
        $this->get('/agent-access/hierarchy')->assertOk()->assertSee('finance-refund-support')->assertDontSee('SameDepartment');
    }

    public function test_notification_job_is_queued_after_commit_and_not_on_rollback(): void
    {
        Queue::fake();
        DB::beginTransaction();
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === NotifyAccessEvent::class && $job->afterCommit);
    }

    public function test_database_delivery_is_redacted_retry_safe_and_rechecks_visibility(): void
    {
        Queue::fake();
        $this->createNotificationStorage();
        $users = [$this->support, $this->manager];
        app()->instance(NotificationRecipients::class, new class($users) implements NotificationRecipients
        {
            public function __construct(private array $users) {}

            public function recipients(AccessEvent $event): iterable
            {
                return array_merge(array_map(fn (User $user) => new Principal($user), $this->users), [new Principal($this->users[0], 'other-realm')]);
            }
        });
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args(), reason: 'NEVER_NOTIFY_THIS_REASON');
        $this->manager->removeRole('finance_manager');
        $job = Queue::pushed(CallQueuedListener::class)->first();
        $job->setJob($this->createMock(Job::class));
        $job->handle($this->app);
        $job->handle($this->app);
        self::assertSame(1, DB::table('notifications')->count());
        $payload = DB::table('notifications')->value('data');
        self::assertStringNotContainsString('NEVER_NOTIFY_THIS_REASON', $payload);
        self::assertStringNotContainsString('amount_minor', $payload);
        self::assertSame('ACCESS_REQUEST_CREATED', json_decode($payload, true)['event']);
        $this->manager->assignRole('finance_manager');
        $job->handle($this->app);
        self::assertSame(2, DB::table('notifications')->count());
    }

    public function test_notification_delivery_and_retries_use_the_recipient_database_connection(): void
    {
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        config(['database.connections.notification_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection('notification_fixture')->create('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
        $this->createNotificationStorage('notification_fixture');
        $recipient = new User(['id' => $this->support->getKey(), 'name' => 'Support']);
        $recipient->setConnection('notification_fixture');
        $recipient->save();
        $this->useNotificationRecipient($recipient);
        $job = Queue::pushed(CallQueuedListener::class)->first();
        $job->setJob($this->createMock(Job::class));
        $job->handle($this->app);
        $job->handle($this->app);
        self::assertFalse(Schema::hasTable('notifications'));
        self::assertSame(1, DB::connection('notification_fixture')->table('notifications')->count());
        self::assertSame(1, $recipient->notifications()->count());
    }

    public function test_a_notification_owned_by_someone_else_does_not_count_as_successful_delivery(): void
    {
        $this->createNotificationStorage();
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->useNotificationRecipient($this->support);
        $job = Queue::pushed(CallQueuedListener::class)->first();
        $notification = new AccessWorkflowNotification($job->data[0], new Principal($this->support));
        $this->manager->notifications()->create(['id' => $notification->id, 'type' => 'fixture', 'data' => []]);
        $job->setJob($this->createMock(Job::class));
        $this->expectException(UniqueConstraintViolationException::class);
        $job->handle($this->app);
    }

    public function test_notification_insert_conflict_is_rechecked_after_another_delivery(): void
    {
        $this->createNotificationStorage();
        app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $this->useNotificationRecipient($this->support);
        $job = Queue::pushed(CallQueuedListener::class)->first();
        $notification = new AccessWorkflowNotification($job->data[0], new Principal($this->support));
        $inserted = false;
        DB::listen(function (QueryExecuted $query) use (&$inserted, $notification): void {
            if (! $inserted && str_starts_with(strtolower($query->sql), 'select exists') && str_contains($query->sql, 'notifications')) {
                // Simulate delivery after the first existence read but before this job's insert.
                $inserted = true;
                $this->support->notifications()->create(['id' => $notification->id, 'type' => AccessWorkflowNotification::class,
                    'data' => $notification->toArray($this->support)]);
            }
        });
        $job->setJob($this->createMock(Job::class));
        $job->handle($this->app);
        self::assertTrue($inserted);
        self::assertSame(1, $this->support->notifications()->count());
    }

    private function createNotificationStorage(?string $connection = null): void
    {
        Schema::connection($connection)->create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->numericMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function useNotificationRecipient(User $recipient): void
    {
        app()->instance(NotificationRecipients::class, new class($recipient) implements NotificationRecipients
        {
            public function __construct(private readonly User $recipient) {}

            public function recipients(AccessEvent $event): iterable
            {
                return [new Principal($this->recipient)];
            }
        });
    }

    public function test_workbench_notifications_skip_an_owner_deleted_before_queued_delivery(): void
    {
        Notification::fake();
        app()->bind(NotificationRecipients::class, WorkflowRecipients::class);
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        $jobs = Queue::pushed(CallQueuedListener::class);
        self::assertCount(2, $jobs);
        $resolver = app(WorkflowRecipients::class);
        self::assertSame((string) $this->manager->getKey(), iterator_to_array($resolver->recipients($jobs[0]->data[0]))[0]->id);
        self::assertSame((string) $this->support->getKey(), iterator_to_array($resolver->recipients($jobs[1]->data[0]))[0]->id);

        $this->support->delete();
        foreach ($jobs as $job) {
            $job->setJob($this->createMock(Job::class));
            $job->handle($this->app);
            $job->handle($this->app);
        }
        Notification::assertNothingSent();
    }

    public function test_workbench_notification_resolver_does_not_hide_configuration_errors(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        config(['agent-access.principal_model' => \stdClass::class]);
        $this->expectException(\LogicException::class);
        iterator_to_array(app(WorkflowRecipients::class)->recipients(new AccessEvent('ACCESS_REQUEST_CREATED', 'test', ['request_id' => $request->id])));
    }

    public function test_doctor_reports_missing_notification_storage_and_unsuitable_queue_without_writing(): void
    {
        config(['queue.default' => 'sync']);
        $before = AuditEvent::query()->count();
        $report = app(InstallationDoctor::class)->check();
        self::assertFalse($report['healthy']);
        $checks = array_column($report['checks'], 'reason_code', 'check');
        self::assertSame('HOST_MIGRATION_REQUIRED', $checks['notification_storage']);
        self::assertSame('ASYNC_QUEUE_REQUIRED', $checks['notification_queue']);
        self::assertSame($before, AuditEvent::query()->count());
    }
}
