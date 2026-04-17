<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'SUPPLIER_CREDIT_NOTE'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'SUPPLIER_CREDIT'");

        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('party_id');
            $table->uuid('supplier_invoice_id')->nullable();
            $table->uuid('inv_grn_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->string('reference_no', 128)->nullable();
            $table->date('credit_date');
            $table->string('currency_code', 3)->nullable();
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 32)->default('DRAFT');
            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices')->nullOnDelete();
            $table->foreign('inv_grn_id')->references('id')->on('inv_grns')->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->nullOnDelete();
            $table->foreign('posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->index(['tenant_id', 'party_id', 'status']);
            $table->index(['tenant_id', 'supplier_invoice_id']);
        });

        DB::statement('ALTER TABLE supplier_credit_notes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE supplier_credit_notes ADD CONSTRAINT supplier_credit_notes_amount_positive CHECK (total_amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_credit_notes DROP CONSTRAINT IF EXISTS supplier_credit_notes_amount_positive');
        Schema::dropIfExists('supplier_credit_notes');
        // Enum values cannot be removed safely on PostgreSQL.
    }
};
