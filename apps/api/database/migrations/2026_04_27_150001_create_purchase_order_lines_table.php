<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('purchase_order_id')->nullable(false);
            $table->integer('line_no')->nullable(false);
            $table->uuid('item_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('qty_ordered', 18, 6)->nullable(false)->default(0);
            $table->decimal('qty_overbill_tolerance', 18, 6)->nullable(false)->default(0);
            $table->decimal('expected_unit_cost', 18, 6)->nullable()->default(null);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inv_items');

            $table->unique(['tenant_id', 'purchase_order_id', 'line_no'], 'po_lines_po_line_no_unique');
            $table->index(['tenant_id']);
            $table->index(['purchase_order_id']);
            $table->index(['item_id']);
        });

        DB::statement('ALTER TABLE purchase_order_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_qty_ordered_nonneg CHECK (qty_ordered >= 0)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_qty_overbill_tol_nonneg CHECK (qty_overbill_tolerance >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_order_lines DROP CONSTRAINT IF EXISTS purchase_order_lines_qty_overbill_tol_nonneg');
        DB::statement('ALTER TABLE purchase_order_lines DROP CONSTRAINT IF EXISTS purchase_order_lines_qty_ordered_nonneg');
        Schema::dropIfExists('purchase_order_lines');
    }
};

