<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_catalog_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('default_name');
            $table->string('scientific_name')->nullable();
            $table->string('category');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE crop_catalog_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE crop_catalog_items ADD CONSTRAINT crop_catalog_items_category_check CHECK (category IN ('cereal', 'legume', 'oilseed', 'vegetable', 'fruit', 'fodder', 'fiber', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_catalog_items');
    }
};
