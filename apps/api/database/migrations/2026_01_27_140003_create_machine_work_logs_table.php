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
            CREATE TYPE machine_work_log_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('machine_work_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('work_log_no')->nullable(false);
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->uuid('machine_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('crop_cycle_id')->nullable(false);
            $table->date('work_date')->nullable();
            $table->decimal('meter_start', 12, 2)->nullable();
            $table->decimal('meter_end', 12, 2)->nullable();
            $table->decimal('usage_qty', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->date('posting_date')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('reversal_posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('machine_id')->references('id')->on('machines');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            
            $table->unique(['tenant_id', 'work_log_no']);
            $table->index(['tenant_id']);
            $table->index(['status']);
            $table->index(['machine_id']);
            $table->index(['project_id']);
            $table->index(['crop_cycle_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'machine_id']);
        });

        DB::statement('ALTER TABLE machine_work_logs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE machine_work_logs DROP COLUMN status');
        DB::statement("ALTER TABLE machine_work_logs ADD COLUMN status machine_work_log_status NOT NULL DEFAULT 'DRAFT'");
        
        // Add CHECK constraint for meter_end >= meter_start
        DB::statement("ALTER TABLE machine_work_logs ADD CONSTRAINT machine_work_logs_meter_range CHECK (meter_end IS NULL OR meter_start IS NULL OR meter_end >= meter_start)");
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_work_logs');
        DB::statement('DROP TYPE IF EXISTS machine_work_log_status');
    }
};
