<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('sku')->nullable();
            $table->uuid('category_id')->nullable();
            $table->uuid('uom_id')->nullable(false);
            $table->string('valuation_method')->nullable(false)->default('WAC');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('category_id')->references('id')->on('inv_item_categories');
            $table->foreign('uom_id')->references('id')->on('inv_uoms');
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id']);
        });

        DB::statement('ALTER TABLE inv_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('CREATE UNIQUE INDEX inv_items_tenant_sku_unique ON inv_items(tenant_id, sku) WHERE sku IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inv_items_tenant_sku_unique');
        Schema::dropIfExists('inv_items');
    }
};
