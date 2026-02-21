<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Platform-level audit log (tenant password reset, archive/unarchive, etc.).
     */
    public function up(): void
    {
        Schema::create('platform_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->nullable(false);
            $table->string('action', 64)->nullable(false);
            $table->uuid('target_tenant_id')->nullable();
            $table->string('target_entity_type', 64)->nullable();
            $table->uuid('target_entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->foreign('target_tenant_id')->references('id')->on('tenants');
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['target_tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE platform_audit_log ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_log');
    }
};
