<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Identity/auth events: platform login, tenant login, invitation, role change, impersonation.
     */
    public function up(): void
    {
        Schema::create('identity_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action')->nullable(false);
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->index(['tenant_id', 'action', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE identity_audit_log ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_audit_log');
    }
};
