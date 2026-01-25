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
            CREATE TYPE lab_work_log_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('lab_work_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('doc_no')->nullable(false);
            $table->uuid('worker_id')->nullable(false);
            $table->date('work_date')->nullable(false);
            $table->uuid('crop_cycle_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('activity_id')->nullable();
            $table->string('rate_basis')->nullable(false);
            $table->decimal('units', 18, 6)->nullable(false);
            $table->decimal('rate', 18, 6)->nullable(false);
            $table->decimal('amount', 18, 2)->nullable(false);
            $table->text('notes')->nullable();
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->date('posting_date')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('worker_id')->references('id')->on('lab_workers');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'doc_no']);
            $table->index(['tenant_id', 'worker_id', 'work_date']);
            $table->index(['tenant_id', 'crop_cycle_id', 'project_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE lab_work_logs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE lab_work_logs DROP COLUMN rate_basis');
        DB::statement("ALTER TABLE lab_work_logs ADD COLUMN rate_basis lab_rate_basis NOT NULL DEFAULT 'DAILY'");
        DB::statement('ALTER TABLE lab_work_logs DROP COLUMN status');
        DB::statement("ALTER TABLE lab_work_logs ADD COLUMN status lab_work_log_status NOT NULL DEFAULT 'DRAFT'");
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_work_logs');
        DB::statement('DROP TYPE IF EXISTS lab_work_log_status');
    }
};
