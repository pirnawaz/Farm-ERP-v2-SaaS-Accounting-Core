<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'OVERHEAD_ALLOCATION'");
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'BILL_RECOGNITION_DEFERRAL'");
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'BILL_RECOGNITION'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'OVERHEAD_ALLOCATION'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'BILL_RECOGNITION'");

        Schema::create('overhead_allocation_headers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('cost_center_id');
            $table->uuid('source_posting_group_id');
            $table->date('allocation_date');
            $table->string('method', 32);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->uuid('posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('cost_center_id')->references('id')->on('cost_centers');
            $table->foreign('source_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'cost_center_id']);
        });
        DB::statement('ALTER TABLE overhead_allocation_headers ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE overhead_allocation_headers ADD CONSTRAINT overhead_allocation_headers_method_check CHECK (method IN ('PERCENTAGE', 'EQUAL_SHARE', 'AREA'))");
        DB::statement("ALTER TABLE overhead_allocation_headers ADD CONSTRAINT overhead_allocation_headers_status_check CHECK (status IN ('DRAFT', 'POSTED'))");

        Schema::create('overhead_allocation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('overhead_allocation_header_id');
            $table->uuid('project_id');
            $table->decimal('amount', 18, 2);
            $table->decimal('percent', 10, 4)->nullable();
            $table->decimal('basis_value', 18, 4)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('overhead_allocation_header_id', 'oal_header_fk')
                ->references('id')->on('overhead_allocation_headers')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects');
            $table->index(['overhead_allocation_header_id']);
        });
        DB::statement('ALTER TABLE overhead_allocation_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::create('bill_recognition_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('supplier_invoice_id');
            $table->string('treatment', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('frequency', 20)->default('MONTHLY');
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 24)->default('DRAFT');
            $table->uuid('deferral_posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices');
            $table->foreign('deferral_posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->index(['tenant_id', 'supplier_invoice_id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('ALTER TABLE bill_recognition_schedules ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE bill_recognition_schedules ADD CONSTRAINT br_sched_treatment_check CHECK (treatment IN ('PREPAID', 'ACCRUAL'))");
        DB::statement("ALTER TABLE bill_recognition_schedules ADD CONSTRAINT br_sched_freq_check CHECK (frequency IN ('MONTHLY'))");
        DB::statement("ALTER TABLE bill_recognition_schedules ADD CONSTRAINT br_sched_status_check CHECK (status IN ('DRAFT', 'DEFERRAL_POSTED', 'COMPLETED'))");

        Schema::create('bill_recognition_schedule_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bill_recognition_schedule_id');
            $table->unsignedInteger('period_no');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 18, 2);
            $table->date('recognition_due_date');
            $table->string('status', 16)->default('PENDING');
            $table->uuid('recognition_posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bill_recognition_schedule_id', 'brsl_sched_fk')
                ->references('id')->on('bill_recognition_schedules')->cascadeOnDelete();
            $table->foreign('recognition_posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->index(['bill_recognition_schedule_id', 'status']);
        });
        DB::statement('ALTER TABLE bill_recognition_schedule_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE bill_recognition_schedule_lines ADD CONSTRAINT brsl_status_check CHECK (status IN ('PENDING', 'POSTED'))");

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('accounts')->insertOrIgnore([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PREPAID_EXPENSE',
                'name' => 'Prepaid Expenses',
                'type' => 'asset',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_recognition_schedule_lines');
        Schema::dropIfExists('bill_recognition_schedules');
        Schema::dropIfExists('overhead_allocation_lines');
        Schema::dropIfExists('overhead_allocation_headers');

        // Enum values cannot be removed safely in PostgreSQL; leave types extended.
    }
};
