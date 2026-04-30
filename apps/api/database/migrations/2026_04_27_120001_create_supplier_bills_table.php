<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_id')->nullable(false);

            $table->string('reference_no', 128)->nullable();
            $table->date('bill_date')->nullable(false);
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3)->nullable(false)->default('GBP');

            // CASH vs CREDIT pricing mode for the entire bill (lines store both prices).
            $table->string('payment_terms', 20)->nullable(false)->default('CASH');

            // Draft-only in AP-1.
            $table->string('status', 20)->nullable(false)->default('DRAFT');

            // Stored totals for visibility/auditability (computed server-side).
            $table->decimal('subtotal_cash_amount', 18, 2)->nullable(false)->default(0);
            $table->decimal('credit_premium_total', 18, 2)->nullable(false)->default(0);
            $table->decimal('grand_total', 18, 2)->nullable(false)->default(0);

            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'bill_date']);
        });

        DB::statement('ALTER TABLE supplier_bills ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_status_check CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED'))");
        DB::statement("ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_payment_terms_check CHECK (payment_terms IN ('CASH', 'CREDIT'))");
        DB::statement('ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_totals_non_negative CHECK (subtotal_cash_amount >= 0 AND credit_premium_total >= 0 AND grand_total >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bills DROP CONSTRAINT IF EXISTS supplier_bills_totals_non_negative');
        DB::statement('ALTER TABLE supplier_bills DROP CONSTRAINT IF EXISTS supplier_bills_payment_terms_check');
        DB::statement('ALTER TABLE supplier_bills DROP CONSTRAINT IF EXISTS supplier_bills_status_check');
        Schema::dropIfExists('supplier_bills');
    }
};

