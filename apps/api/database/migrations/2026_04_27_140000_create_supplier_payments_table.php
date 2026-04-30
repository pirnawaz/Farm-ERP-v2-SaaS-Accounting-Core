<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_id')->nullable(false);

            $table->date('payment_date')->nullable(false);
            $table->date('posting_date')->nullable();

            $table->string('payment_method', 20)->nullable(false)->default('CASH'); // CASH | BANK
            $table->uuid('bank_account_id')->nullable(); // Account id (asset) when BANK; optional for CASH

            $table->string('status', 20)->nullable(false)->default('DRAFT'); // DRAFT | POSTED | VOIDED
            $table->decimal('total_amount', 18, 2)->nullable(false)->default(0);
            $table->text('notes')->nullable();

            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('bank_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'payment_date']);
        });

        DB::statement('ALTER TABLE supplier_payments ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_status_check CHECK (status IN ('DRAFT', 'POSTED', 'VOIDED'))");
        DB::statement("ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_method_check CHECK (payment_method IN ('CASH', 'BANK'))");
        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_check CHECK (total_amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT IF EXISTS supplier_payments_amount_check');
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT IF EXISTS supplier_payments_method_check');
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT IF EXISTS supplier_payments_status_check');
        Schema::dropIfExists('supplier_payments');
    }
};

