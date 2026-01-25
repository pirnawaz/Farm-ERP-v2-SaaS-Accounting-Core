<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('store_id')->nullable(false);
            $table->uuid('item_id')->nullable(false);
            $table->decimal('qty_on_hand', 18, 6)->default(0);
            $table->decimal('value_on_hand', 18, 2)->default(0);
            $table->decimal('wac_cost', 18, 6)->default(0);
            $table->timestampTz('updated_at')->nullable(false)->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->foreign('item_id')->references('id')->on('inv_items');
            $table->unique(['tenant_id', 'store_id', 'item_id']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'store_id', 'item_id']);
        });

        DB::statement('ALTER TABLE inv_stock_balances ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_balances');
    }
};
