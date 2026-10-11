<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAgentAccess\InstallationDoctor;
use LaravelAgentAccess\Models\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\EmailIdentityUser;
use Tests\Fixtures\NotificationConnectionUser;
use Tests\Fixtures\StringUser;

final class DoctorTest extends TestCase
{
    public function test_installed_schema_is_healthy_without_writing_or_running_migrations(): void
    {
        self::assertTrue(app(InstallationDoctor::class)->check()['healthy']);
        $this->artisan('agent-access:doctor')->assertSuccessful();
        $this->artisan('agent-access:doctor', ['--json' => true])->assertSuccessful();
        self::assertSame(0, AuditEvent::query()->count());
    }

    public function test_missing_schema_and_indexes_are_reported_with_nonzero_exit(): void
    {
        Schema::drop('agent_access_audit_events');
        $report = app(InstallationDoctor::class)->check();
        self::assertFalse($report['healthy']);
        self::assertContains('MIGRATION_REQUIRED', array_column($report['checks'], 'reason_code'));
        $this->artisan('agent-access:doctor')->assertFailed();
        self::assertFalse(Schema::hasTable('agent_access_audit_events'));
    }

    public function test_wrong_principal_model_does_not_expose_application_class_or_secrets(): void
    {
        config(['agent-access.principal_model' => \stdClass::class, 'database.connections.sqlite.password' => 'private-fixture-password']);
        $report = app(InstallationDoctor::class)->check();
        self::assertFalse($report['healthy']);
        self::assertStringNotContainsString('stdClass', json_encode($report));
        self::assertStringNotContainsString('private-fixture-password', json_encode($report));
    }

    #[DataProvider('notificationMorphs')]
    public function test_notification_storage_checks_string_primary_keys(string $morphs, bool $compatible): void
    {
        config(['agent-access.notifications.enabled' => true, 'agent-access.principal_model' => StringUser::class]);
        $this->createNotificationStorage($morphs);
        $before = AuditEvent::query()->count();
        $report = app(InstallationDoctor::class)->check();
        $checks = array_column($report['checks'], null, 'check');
        self::assertTrue($checks['notification_storage']['passed']);
        self::assertSame($compatible, $checks['notification_identity']['passed']);
        self::assertSame($compatible ? 'COMPATIBLE_KEY_TYPE' : 'NOTIFICATION_KEY_TYPE_MISMATCH', $checks['notification_identity']['reason_code']);
        self::assertSame($before, AuditEvent::query()->count());
    }

    public static function notificationMorphs(): iterable
    {
        yield 'integer column cannot store UUID or ULID' => ['numericMorphs', false];
        yield 'UUID column' => ['uuidMorphs', true];
        yield 'ULID column' => ['ulidMorphs', true];
    }

    public function test_notification_identity_uses_the_model_primary_key_instead_of_its_authentication_identifier(): void
    {
        config(['agent-access.notifications.enabled' => true, 'agent-access.principal_model' => EmailIdentityUser::class]);
        $this->createNotificationStorage('numericMorphs');
        $checks = array_column(app(InstallationDoctor::class)->check()['checks'], null, 'check');
        self::assertTrue($checks['notification_identity']['passed']);
    }

    public function test_notification_storage_is_checked_on_the_principal_model_connection(): void
    {
        config(['agent-access.notifications.enabled' => true, 'agent-access.principal_model' => NotificationConnectionUser::class,
            'database.connections.notification_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection('notification_fixture')->create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->numericMorphs('notifiable');
            $table->text('data');
        });
        self::assertFalse(Schema::hasTable('notifications'));
        $checks = array_column(app(InstallationDoctor::class)->check()['checks'], null, 'check');
        self::assertTrue($checks['notification_storage']['passed']);
        self::assertTrue($checks['notification_identity']['passed']);
    }

    public function test_unavailable_queue_storage_does_not_overwrite_healthy_notification_storage(): void
    {
        $this->createNotificationStorage('numericMorphs');
        config(['agent-access.notifications.enabled' => true, 'queue.default' => 'fixture',
            'queue.connections.fixture' => ['driver' => 'database', 'connection' => 'private-unavailable-connection', 'table' => 'jobs']]);
        $report = app(InstallationDoctor::class)->check();
        $names = array_column($report['checks'], 'check');
        self::assertCount(count($names), array_unique($names));
        $checks = array_column($report['checks'], null, 'check');
        self::assertTrue($checks['notification_storage']['passed']);
        self::assertTrue($checks['notification_queue']['passed']);
        self::assertSame('DATABASE_UNAVAILABLE', $checks['notification_queue_storage']['reason_code']);
        self::assertFalse($checks['notification_queue_storage']['passed']);
        self::assertFalse($report['healthy']);
        self::assertStringNotContainsString('private-unavailable-connection', json_encode($report));
        self::assertSame(0, AuditEvent::query()->count());
        $this->artisan('agent-access:doctor', ['--json' => true])->assertFailed();
    }

    public function test_unavailable_notification_storage_does_not_skip_queue_diagnostics(): void
    {
        config(['agent-access.notifications.enabled' => true, 'agent-access.principal_model' => NotificationConnectionUser::class,
            'database.connections.notification_fixture' => ['driver' => 'private-unsupported-driver'],
            'queue.default' => 'fixture', 'queue.connections.fixture' => ['driver' => 'database', 'connection' => null, 'table' => 'jobs']]);
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $report = app(InstallationDoctor::class)->check();
        $checks = array_column($report['checks'], null, 'check');
        self::assertSame('DATABASE_UNAVAILABLE', $checks['notification_storage']['reason_code']);
        self::assertFalse($checks['notification_storage']['passed']);
        self::assertTrue($checks['notification_queue']['passed']);
        self::assertSame('INSTALLED', $checks['notification_queue_storage']['reason_code']);
        self::assertTrue($checks['notification_queue_storage']['passed']);
        self::assertFalse($report['healthy']);
        self::assertStringNotContainsString('private-unsupported-driver', json_encode($report));
        self::assertSame(0, AuditEvent::query()->count());
    }

    private function createNotificationStorage(string $morphs): void
    {
        Schema::create('notifications', function (Blueprint $table) use ($morphs): void {
            $table->uuid('id')->primary();
            $table->{$morphs}('notifiable');
            $table->text('data');
        });
    }
}
