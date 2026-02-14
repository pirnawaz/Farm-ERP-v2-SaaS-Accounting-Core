<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Audit log for platform admin impersonation (START/STOP).
     * Platform-level; no tenant_id (actor may be from any tenant).
     */
    public function up(): void
    {
        Schema::create('impersonation_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('target_tenant_id')->nullable(false);
            $table->uuid('target_user_id')->nullable();
            $table->string('action', 16)->nullable(false); // START, STOP
            $table->json('metadata')->nullable(); // ip, user_agent, etc.
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->foreign('target_tenant_id')->references('id')->on('tenants');
            $table->foreign('target_user_id')->references('id')->on('users');
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['target_tenant_id', 'created_at']);
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE impersonation_audit_log ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_audit_log');
    }
};
