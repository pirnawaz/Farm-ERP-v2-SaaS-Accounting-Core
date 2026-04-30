<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add posting enum + allocation enum values (additive; cannot remove safely in down on Postgres).
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'SUPPLIER_BILL'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'SUPPLIER_BILL_BASE'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'SUPPLIER_BILL_CREDIT_PREMIUM'");

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->uuid('posting_group_id')->nullable()->after('status');
            $table->date('posting_date')->nullable()->after('posting_group_id');
            $table->timestampTz('posted_at')->nullable()->after('posting_date');
            $table->uuid('posted_by')->nullable()->after('posted_at');
            $table->index(['tenant_id', 'posting_group_id']);
            $table->index(['tenant_id', 'posting_date']);
            $table->foreign('posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
        });

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            // Allocation requirements for posting (nullable in draft; required at post time).
            $table->uuid('project_id')->nullable()->after('description');
            $table->uuid('crop_cycle_id')->nullable()->after('project_id');
            $table->string('cost_category', 20)->nullable(false)->default('OTHER')->after('crop_cycle_id');

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->nullOnDelete();
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'crop_cycle_id']);
        });

        DB::statement("ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_cost_category_check CHECK (cost_category IN ('INPUT', 'SERVICE', 'REPAIR', 'OTHER'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT IF EXISTS supplier_bill_lines_cost_category_check');

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['crop_cycle_id']);
            $table->dropIndex(['tenant_id', 'project_id']);
            $table->dropIndex(['tenant_id', 'crop_cycle_id']);
            $table->dropColumn(['project_id', 'crop_cycle_id', 'cost_category']);
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropForeign(['posting_group_id']);
            $table->dropIndex(['tenant_id', 'posting_group_id']);
            $table->dropIndex(['tenant_id', 'posting_date']);
            $table->dropColumn(['posting_group_id', 'posting_date', 'posted_at', 'posted_by']);
        });

        // Enum values cannot be removed safely in PostgreSQL.
    }
};

