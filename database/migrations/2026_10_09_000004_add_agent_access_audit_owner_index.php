<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_access_audit_events', fn (Blueprint $t) => $t->index(['actor_type', 'actor_id', 'correlation_id'], 'laa_audit_actor_correlation'));
    }

    public function down(): void
    {
        Schema::table('agent_access_audit_events', fn (Blueprint $t) => $t->dropIndex('laa_audit_actor_correlation'));
    }
};
