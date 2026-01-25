<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->string('cost_type')->nullable(false);
            $table->decimal('amount', 14, 2)->nullable(false);
            $table->char('currency_code', 3)->default('GBP')->nullable(false);
            $table->string('rule_version')->nullable();
            $table->string('rule_hash')->nullable();
            $table->jsonb('rule_snapshot_json')->nullable();
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->index(['tenant_id']);
            $table->index(['posting_group_id']);
            $table->index(['project_id']);
        });
        
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_check CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_rows');
    }
};
