<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('entity_type')->nullable(false); // e.g., 'Sale', 'Payment', 'PostingGroup'
            $table->uuid('entity_id')->nullable(false);
            $table->string('action')->nullable(false); // 'POST', 'REVERSE', 'CREATE', 'UPDATE'
            $table->uuid('user_id')->nullable(false);
            $table->string('user_email')->nullable(); // Denormalized for queries
            $table->json('metadata')->nullable(); // reason, posting_date, etc.
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'action', 'created_at']);
        });

        // Set default UUID generation for PostgreSQL
        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
