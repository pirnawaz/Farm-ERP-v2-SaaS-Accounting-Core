<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AP allocation rail: supplier payments (OUT) applied to posted supplier invoices (partial payments allowed).
     * Outstanding per invoice = invoice total_amount minus sum of ACTIVE allocations.
     */
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_invoice_id')->nullable(false);
            $table->uuid('payment_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->date('allocation_date')->nullable(false);
            $table->decimal('amount', 18, 2)->nullable(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            $table->uuid('created_by')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestampTz('voided_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices');
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');

            $table->index(['tenant_id', 'supplier_invoice_id']);
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'allocation_date']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE supplier_payment_allocations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE supplier_payment_allocations ADD CONSTRAINT supplier_payment_allocations_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE supplier_payment_allocations ADD CONSTRAINT supplier_payment_allocations_status_check CHECK (status IN ('ACTIVE', 'VOID'))");
        DB::statement('CREATE UNIQUE INDEX supplier_payment_allocations_active_unique ON supplier_payment_allocations (tenant_id, payment_id, supplier_invoice_id, posting_group_id) WHERE (status = \'ACTIVE\' OR status IS NULL)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS supplier_payment_allocations_active_unique');
        DB::statement('ALTER TABLE supplier_payment_allocations DROP CONSTRAINT IF EXISTS supplier_payment_allocations_status_check');
        DB::statement('ALTER TABLE supplier_payment_allocations DROP CONSTRAINT IF EXISTS supplier_payment_allocations_amount_check');
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
