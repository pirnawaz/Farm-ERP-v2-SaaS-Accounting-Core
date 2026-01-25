<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->decimal('pool_revenue', 12, 2)->nullable(false);
            $table->decimal('shared_costs', 12, 2)->nullable(false);
            $table->decimal('pool_profit', 12, 2)->nullable(false);
            $table->decimal('kamdari_amount', 12, 2)->nullable(false);
            $table->decimal('landlord_share', 12, 2)->nullable(false);
            $table->decimal('hari_share', 12, 2)->nullable(false);
            $table->decimal('hari_only_deductions', 12, 2)->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id']);
            $table->index(['project_id']);
            $table->index(['posting_group_id']);
        });
        
        DB::statement('ALTER TABLE settlements ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
