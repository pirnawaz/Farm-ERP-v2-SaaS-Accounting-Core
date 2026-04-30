<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_receipt_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('purchase_order_line_id')->nullable(false);
            $table->uuid('grn_line_id')->nullable(false);
            $table->decimal('matched_qty', 18, 6)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines')->cascadeOnDelete();
            $table->foreign('grn_line_id')->references('id')->on('inv_grn_lines')->cascadeOnDelete();

            $table->unique(['tenant_id', 'purchase_order_line_id', 'grn_line_id'], 'po_receipt_matches_line_grn_unique');
            $table->index(['tenant_id']);
            $table->index(['purchase_order_line_id']);
            $table->index(['grn_line_id']);
        });

        DB::statement('ALTER TABLE purchase_order_receipt_matches ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE purchase_order_receipt_matches ADD CONSTRAINT po_receipt_matches_qty_positive CHECK (matched_qty > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_order_receipt_matches DROP CONSTRAINT IF EXISTS po_receipt_matches_qty_positive');
        Schema::dropIfExists('purchase_order_receipt_matches');
    }
};

