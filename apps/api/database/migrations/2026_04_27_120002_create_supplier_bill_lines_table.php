<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_bill_id')->nullable(false);

            $table->unsignedInteger('line_no')->nullable(false)->default(1);
            $table->text('description')->nullable();

            $table->decimal('qty', 18, 6)->nullable(false)->default(1);

            // Price visibility requirement: store both.
            $table->decimal('cash_unit_price', 18, 6)->nullable(false)->default(0);
            $table->decimal('credit_unit_price', 18, 6)->nullable();

            // Stored computed amounts (server-side calculation service).
            $table->decimal('base_cash_amount', 18, 2)->nullable(false)->default(0);
            $table->decimal('selected_unit_price', 18, 6)->nullable(false)->default(0);
            $table->decimal('credit_premium_amount', 18, 2)->nullable(false)->default(0);
            $table->decimal('line_total', 18, 2)->nullable(false)->default(0);

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_bill_id')->references('id')->on('supplier_bills')->cascadeOnDelete();

            $table->index(['tenant_id']);
            $table->index(['supplier_bill_id']);
            $table->unique(['tenant_id', 'supplier_bill_id', 'line_no'], 'supplier_bill_lines_bill_line_no_unique');
        });

        DB::statement('ALTER TABLE supplier_bill_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_cash_price_non_negative CHECK (cash_unit_price >= 0)');
        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_credit_price_non_negative CHECK (credit_unit_price IS NULL OR credit_unit_price >= 0)');
        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_amounts_non_negative CHECK (base_cash_amount >= 0 AND credit_premium_amount >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT IF EXISTS supplier_bill_lines_amounts_non_negative');
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT IF EXISTS supplier_bill_lines_credit_price_non_negative');
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT IF EXISTS supplier_bill_lines_cash_price_non_negative');
        DB::statement('ALTER TABLE supplier_bill_lines DROP CONSTRAINT IF EXISTS supplier_bill_lines_qty_positive');
        Schema::dropIfExists('supplier_bill_lines');
    }
};

