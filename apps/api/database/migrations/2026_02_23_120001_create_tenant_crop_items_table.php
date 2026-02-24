<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_crop_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('crop_catalog_item_id')->nullable();
            $table->string('custom_name')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('crop_catalog_item_id')->references('id')->on('crop_catalog_items')->nullOnDelete();
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'crop_catalog_item_id']);
        });

        DB::statement('ALTER TABLE tenant_crop_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        // Unique (tenant_id, crop_catalog_item_id) where crop_catalog_item_id is not null
        DB::statement('CREATE UNIQUE INDEX tenant_crop_items_tenant_catalog_unique ON tenant_crop_items (tenant_id, crop_catalog_item_id) WHERE crop_catalog_item_id IS NOT NULL');
        // Optionally unique (tenant_id, display_name) - we use a unique index where display_name is not null
        DB::statement('CREATE UNIQUE INDEX tenant_crop_items_tenant_display_name_unique ON tenant_crop_items (tenant_id, display_name) WHERE display_name IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_crop_items');
    }
};
