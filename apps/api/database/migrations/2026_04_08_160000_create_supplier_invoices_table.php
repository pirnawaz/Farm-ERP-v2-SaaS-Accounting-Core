<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->uuid('project_id')->nullable();
            $table->uuid('grn_id')->nullable();
            $table->string('reference_no', 128)->nullable();
            $table->date('invoice_date')->nullable();
            $table->char('currency_code', 3)->nullable(false)->default('GBP');
            $table->decimal('subtotal_amount', 18, 2)->nullable(false)->default(0);
            $table->decimal('tax_amount', 18, 2)->nullable(false)->default(0);
            $table->decimal('total_amount', 18, 2)->nullable(false)->default(0);
            $table->string('status', 20)->nullable(false)->default('DRAFT');
            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('grn_id')->references('id')->on('inv_grns')->nullOnDelete();
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'party_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['grn_id']);
        });

        DB::statement('ALTER TABLE supplier_invoices ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_status_check CHECK (status IN ('DRAFT', 'POSTED', 'PAID'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_invoices DROP CONSTRAINT IF EXISTS supplier_invoices_status_check');
        Schema::dropIfExists('supplier_invoices');
    }
};
