<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('sale_id')->nullable(false);
            $table->uuid('inventory_item_id')->nullable(false);
            $table->uuid('store_id')->nullable();
            $table->decimal('quantity', 18, 3)->nullable(false);
            $table->string('uom')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable(false);
            $table->decimal('line_total', 12, 2)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('inventory_item_id')->references('id')->on('inv_items');
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['inventory_item_id']);
        });

        DB::statement('ALTER TABLE sale_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_unit_price_check CHECK (unit_price > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
    }
};
