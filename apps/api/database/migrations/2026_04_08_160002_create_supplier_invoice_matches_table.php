<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('supplier_invoice_line_id')->nullable(false);
            $table->uuid('grn_line_id')->nullable(false);
            $table->decimal('matched_qty', 18, 6)->nullable(false);
            $table->decimal('matched_amount', 18, 2)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_invoice_line_id')->references('id')->on('supplier_invoice_lines')->cascadeOnDelete();
            $table->foreign('grn_line_id')->references('id')->on('inv_grn_lines')->cascadeOnDelete();
            $table->unique(['tenant_id', 'supplier_invoice_line_id', 'grn_line_id'], 'supplier_invoice_matches_line_grn_unique');
            $table->index(['tenant_id']);
            $table->index(['grn_line_id']);
        });

        DB::statement('ALTER TABLE supplier_invoice_matches ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE supplier_invoice_matches ADD CONSTRAINT supplier_invoice_matches_qty_positive CHECK (matched_qty > 0)');
        DB::statement('ALTER TABLE supplier_invoice_matches ADD CONSTRAINT supplier_invoice_matches_amount_positive CHECK (matched_amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_invoice_matches DROP CONSTRAINT IF EXISTS supplier_invoice_matches_amount_positive');
        DB::statement('ALTER TABLE supplier_invoice_matches DROP CONSTRAINT IF EXISTS supplier_invoice_matches_qty_positive');
        Schema::dropIfExists('supplier_invoice_matches');
    }
};
