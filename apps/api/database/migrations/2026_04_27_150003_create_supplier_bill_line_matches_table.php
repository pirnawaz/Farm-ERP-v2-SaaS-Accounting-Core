<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bill_line_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_bill_line_id')->nullable(false);
            $table->uuid('purchase_order_line_id')->nullable();
            $table->uuid('grn_line_id')->nullable();
            $table->decimal('matched_qty', 18, 6)->nullable(false);
            $table->decimal('matched_amount', 18, 2)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_bill_line_id')->references('id')->on('supplier_bill_lines')->cascadeOnDelete();
            $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines')->nullOnDelete();
            $table->foreign('grn_line_id')->references('id')->on('inv_grn_lines')->nullOnDelete();

            $table->unique(['tenant_id', 'supplier_bill_line_id', 'grn_line_id'], 'sb_line_matches_line_grn_unique');
            $table->index(['tenant_id']);
            $table->index(['supplier_bill_line_id']);
            $table->index(['purchase_order_line_id']);
            $table->index(['grn_line_id']);
        });

        DB::statement('ALTER TABLE supplier_bill_line_matches ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE supplier_bill_line_matches ADD CONSTRAINT sb_line_matches_qty_positive CHECK (matched_qty > 0)');
        DB::statement('ALTER TABLE supplier_bill_line_matches ADD CONSTRAINT sb_line_matches_amount_positive CHECK (matched_amount > 0)');
        DB::statement("ALTER TABLE supplier_bill_line_matches ADD CONSTRAINT sb_line_matches_requires_po_or_grn CHECK (purchase_order_line_id IS NOT NULL OR grn_line_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bill_line_matches DROP CONSTRAINT IF EXISTS sb_line_matches_requires_po_or_grn');
        DB::statement('ALTER TABLE supplier_bill_line_matches DROP CONSTRAINT IF EXISTS sb_line_matches_amount_positive');
        DB::statement('ALTER TABLE supplier_bill_line_matches DROP CONSTRAINT IF EXISTS sb_line_matches_qty_positive');
        Schema::dropIfExists('supplier_bill_line_matches');
    }
};

