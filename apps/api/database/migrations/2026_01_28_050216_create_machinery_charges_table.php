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
        // Create Postgres ENUM types
        DB::statement("DO $$ BEGIN
            CREATE TYPE machinery_charge_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Reuse machine_work_log_pool_scope enum (SHARED, HARI_ONLY)
        // Ensure it exists (created in add_pool_scope_to_machine_work_logs migration)
        DB::statement("DO $$ BEGIN
            CREATE TYPE machine_work_log_pool_scope AS ENUM ('SHARED', 'HARI_ONLY');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('machinery_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('charge_no')->nullable(false);
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->uuid('landlord_party_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('crop_cycle_id')->nullable(false);
            $table->string('pool_scope')->nullable(false);
            $table->date('charge_date')->nullable(false);
            $table->date('posting_date')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('reversal_posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('landlord_party_id')->references('id')->on('parties');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            
            $table->unique(['tenant_id', 'charge_no']);
            $table->index(['tenant_id']);
            $table->index(['status']);
            $table->index(['project_id']);
            $table->index(['crop_cycle_id']);
            $table->index(['landlord_party_id']);
        });

        DB::statement('ALTER TABLE machinery_charges ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert status to ENUM
        DB::statement('ALTER TABLE machinery_charges DROP COLUMN status');
        DB::statement("ALTER TABLE machinery_charges ADD COLUMN status machinery_charge_status NOT NULL DEFAULT 'DRAFT'");
        
        // Convert pool_scope to ENUM (reuse machine_work_log_pool_scope)
        DB::statement('ALTER TABLE machinery_charges DROP COLUMN pool_scope');
        DB::statement("ALTER TABLE machinery_charges ADD COLUMN pool_scope machine_work_log_pool_scope NOT NULL DEFAULT 'SHARED'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machinery_charges');
        DB::statement('DROP TYPE IF EXISTS machinery_charge_status');
        // Note: machine_work_log_pool_scope enum is not dropped as it's used by machine_work_logs
    }
};
