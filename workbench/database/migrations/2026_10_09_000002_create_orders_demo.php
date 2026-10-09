<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('department')->default('finance');
            $t->boolean('is_suspended')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t): void {
            $t->id();
            $t->unsignedInteger('amount_minor');
            $t->string('currency', 3)->default('PLN');
            $t->boolean('is_refunded')->default(false);
            $t->boolean('is_cancelled')->default(false);
            $t->timestamps();
        });
        Schema::create('refunds', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('order_id')->unique();
            $t->unsignedInteger('amount_minor');
            $t->string('currency', 3);
        });
        $permissions = require __DIR__.'/../../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $permissions->up();
        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        $permissions = require __DIR__.'/../../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $permissions->down();
        foreach (['refunds', 'orders', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
