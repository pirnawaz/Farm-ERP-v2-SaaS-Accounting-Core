<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_varieties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tenant_crop_item_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('maturity_days')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tenant_crop_item_id')->references('id')->on('tenant_crop_items')->cascadeOnDelete();
            $table->index(['tenant_id']);
            $table->index(['tenant_crop_item_id']);
        });

        DB::statement('ALTER TABLE crop_varieties ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_varieties');
    }
};
