<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Schema;
use LaravelAgentAccess\InstallationDoctor;
use LaravelAgentAccess\Models\AuditEvent;

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
}
