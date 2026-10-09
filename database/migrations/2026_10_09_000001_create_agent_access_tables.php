<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_access_requests', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('principal_type', 32);
            $t->string('principal_id', 128);
            $t->string('action', 128);
            $t->string('permission', 128);
            $t->string('resource_type', 64)->nullable();
            $t->string('resource_id', 128)->nullable();
            $t->json('bound_arguments');
            $t->unsignedInteger('duration_seconds');
            $t->unsignedInteger('max_uses')->nullable();
            $t->string('rule_id', 128);
            $t->string('rule_fingerprint', 64);
            $t->string('status', 16)->default('pending');
            $t->string('reason', 500)->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->string('payload_hash', 64);
            $t->timestampTz('expires_at', 6);
            $t->timestampTz('decided_at', 6)->nullable();
            $t->timestampsTz(6);
            $t->unique(['principal_type', 'principal_id', 'idempotency_key'], 'laa_request_idempotency');
            $t->index(['status', 'expires_at'], 'laa_pending_expiry');
        });
        Schema::create('agent_access_approvals', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('request_id')->unique()->constrained('agent_access_requests')->restrictOnDelete();
            $t->string('actor_type', 32);
            $t->string('actor_id', 128);
            $t->string('decision', 16);
            $t->string('reason', 500)->nullable();
            $t->timestampsTz(6);
        });
        Schema::create('agent_access_grants', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('request_id')->unique()->constrained('agent_access_requests')->restrictOnDelete();
            $t->string('principal_type', 32);
            $t->string('principal_id', 128);
            $t->string('action', 128);
            $t->string('permission', 128);
            $t->string('resource_type', 64)->nullable();
            $t->string('resource_id', 128)->nullable();
            $t->json('bound_arguments');
            $t->string('rule_id', 128);
            $t->string('rule_fingerprint', 64);
            $t->unsignedInteger('duration_seconds');
            $t->timestampTz('valid_from', 6);
            $t->timestampTz('expires_at', 6);
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->string('grantor_type', 32);
            $t->string('grantor_id', 128);
            $t->timestampTz('revoked_at', 6)->nullable();
            $t->string('revoked_by_type', 32)->nullable();
            $t->string('revoked_by_id', 128)->nullable();
            $t->timestampTz('expiry_recorded_at', 6)->nullable();
            $t->timestampsTz(6);
            $t->index(['principal_type', 'principal_id', 'action', 'permission'], 'laa_grant_lookup');
            $t->index(['expires_at', 'expiry_recorded_at'], 'laa_grant_expiry');
        });
        Schema::create('agent_access_grant_uses', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('grant_id')->constrained('agent_access_grants')->restrictOnDelete();
            $t->ulid('execution_id')->unique();
            $t->string('principal_type', 32);
            $t->string('principal_id', 128);
            $t->timestampsTz(6);
        });
        Schema::create('agent_access_audit_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('event_type', 64);
            $t->string('actor_type', 32);
            $t->string('actor_id', 128);
            $t->ulid('request_id')->nullable()->index();
            $t->ulid('grant_id')->nullable()->index();
            $t->ulid('execution_id')->nullable()->index();
            $t->string('correlation_id', 128)->index();
            $t->string('channel', 16);
            $t->json('metadata');
            $t->timestampsTz(6);
        });
    }

    public function down(): void
    {
        foreach (['agent_access_audit_events', 'agent_access_grant_uses', 'agent_access_grants', 'agent_access_approvals', 'agent_access_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
