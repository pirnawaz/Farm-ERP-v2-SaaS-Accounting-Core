<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bill_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_payment_id')->nullable(false);
            $table->uuid('supplier_bill_id')->nullable(false);
            $table->decimal('amount_applied', 18, 2)->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_payment_id')->references('id')->on('supplier_payments')->cascadeOnDelete();
            $table->foreign('supplier_bill_id')->references('id')->on('supplier_bills');

            $table->index(['tenant_id', 'supplier_payment_id']);
            $table->index(['tenant_id', 'supplier_bill_id']);
        });

        DB::statement('ALTER TABLE supplier_bill_payment_allocations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE supplier_bill_payment_allocations ADD CONSTRAINT supplier_bill_payment_allocations_amount_check CHECK (amount_applied > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bill_payment_allocations DROP CONSTRAINT IF EXISTS supplier_bill_payment_allocations_amount_check');
        Schema::dropIfExists('supplier_bill_payment_allocations');
    }
};

