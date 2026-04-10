<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            CREATE TYPE field_job_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('field_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('doc_no')->nullable();
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->date('job_date')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('crop_cycle_id')->nullable(false);
            $table->uuid('production_unit_id')->nullable();
            $table->uuid('land_parcel_id')->nullable();
            $table->uuid('crop_activity_type_id')->nullable();
            $table->text('notes')->nullable();
            $table->date('posting_date')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('reversal_posting_group_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('production_unit_id')->references('id')->on('production_units');
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels');
            $table->foreign('crop_activity_type_id')->references('id')->on('crop_activity_types');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('created_by')->references('id')->on('users');

            $table->unique(['tenant_id', 'doc_no']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'crop_cycle_id']);
            $table->index(['tenant_id', 'job_date']);
        });

        DB::statement('ALTER TABLE field_jobs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE field_jobs DROP COLUMN status');
        DB::statement("ALTER TABLE field_jobs ADD COLUMN status field_job_status NOT NULL DEFAULT 'DRAFT'");

        Schema::create('field_job_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('field_job_id')->nullable(false);
            $table->uuid('store_id')->nullable(false);
            $table->uuid('item_id')->nullable(false);
            $table->decimal('qty', 18, 6)->nullable(false);
            $table->decimal('unit_cost_snapshot', 18, 6)->nullable();
            $table->decimal('line_total', 18, 2)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('field_job_id')->references('id')->on('field_jobs')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->foreign('item_id')->references('id')->on('inv_items');
            $table->index(['tenant_id', 'field_job_id']);
        });

        DB::statement('ALTER TABLE field_job_inputs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE field_job_inputs ADD CONSTRAINT field_job_inputs_qty_positive CHECK (qty > 0)');

        Schema::create('field_job_labour', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('field_job_id')->nullable(false);
            $table->uuid('worker_id')->nullable(false);
            $table->string('rate_basis')->nullable();
            $table->decimal('units', 18, 6)->nullable(false);
            $table->decimal('rate', 18, 6)->nullable(false);
            $table->decimal('amount', 18, 2)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('field_job_id')->references('id')->on('field_jobs')->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on('lab_workers');
            $table->index(['tenant_id', 'field_job_id']);
        });

        DB::statement('ALTER TABLE field_job_labour ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE field_job_labour DROP COLUMN rate_basis');
        DB::statement("ALTER TABLE field_job_labour ADD COLUMN rate_basis lab_rate_basis NOT NULL DEFAULT 'DAILY'");
        DB::statement('ALTER TABLE field_job_labour ADD CONSTRAINT field_job_labour_units_positive CHECK (units > 0)');
        DB::statement('ALTER TABLE field_job_labour ADD CONSTRAINT field_job_labour_rate_non_neg CHECK (rate >= 0)');

        Schema::create('field_job_machines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('field_job_id')->nullable(false);
            $table->uuid('machine_id')->nullable(false);
            $table->decimal('usage_qty', 12, 2)->nullable(false)->default(0);
            $table->string('meter_unit_snapshot')->nullable();
            $table->decimal('rate_snapshot', 18, 6)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->uuid('source_work_log_id')->nullable();
            $table->uuid('source_charge_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('field_job_id')->references('id')->on('field_jobs')->cascadeOnDelete();
            $table->foreign('machine_id')->references('id')->on('machines');
            $table->foreign('source_work_log_id')->references('id')->on('machine_work_logs')->nullOnDelete();
            $table->foreign('source_charge_id')->references('id')->on('machinery_charges')->nullOnDelete();
            $table->index(['tenant_id', 'field_job_id']);
        });

        DB::statement('ALTER TABLE field_job_machines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE field_job_machines ADD CONSTRAINT field_job_machines_usage_qty_non_neg CHECK (usage_qty >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_job_machines');
        Schema::dropIfExists('field_job_labour');
        Schema::dropIfExists('field_job_inputs');
        Schema::dropIfExists('field_jobs');

        DB::statement('DROP TYPE IF EXISTS field_job_status');
    }
};
