<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_access_requests', fn (Blueprint $t) => $t->index(['rule_id', 'principal_type', 'status'], 'laa_request_rule_visibility'));
        Schema::table('agent_access_grants', function (Blueprint $t): void {
            $t->index(['rule_id', 'principal_type'], 'laa_grant_rule_visibility');
            $t->index(['resource_type', 'resource_id', 'expires_at'], 'laa_grant_resource');
        });
    }

    public function down(): void
    {
        Schema::table('agent_access_requests', fn (Blueprint $t) => $t->dropIndex('laa_request_rule_visibility'));
        Schema::table('agent_access_grants', function (Blueprint $t): void {
            $t->dropIndex('laa_grant_rule_visibility');
            $t->dropIndex('laa_grant_resource');
        });
    }
};
