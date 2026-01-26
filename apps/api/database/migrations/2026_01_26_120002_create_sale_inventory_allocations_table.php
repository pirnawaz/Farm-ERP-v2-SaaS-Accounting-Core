<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_inventory_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('sale_id')->nullable(false);
            $table->uuid('sale_line_id')->nullable(false);
            $table->uuid('inventory_item_id')->nullable(false);
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('store_id')->nullable(false);
            $table->decimal('quantity', 18, 3)->nullable(false);
            $table->decimal('unit_cost', 18, 6)->nullable(false);
            $table->decimal('total_cost', 12, 2)->nullable(false);
            $table->string('costing_method')->nullable(false)->default('WAC');
            $table->uuid('posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->foreign('sale_line_id')->references('id')->on('sale_lines');
            $table->foreign('inventory_item_id')->references('id')->on('inv_items');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'crop_cycle_id']);
            $table->index(['inventory_item_id']);
        });

        DB::statement('ALTER TABLE sale_inventory_allocations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('CREATE UNIQUE INDEX sale_inventory_allocations_tenant_sale_line_unique ON sale_inventory_allocations(tenant_id, sale_line_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_inventory_allocations');
    }
};
