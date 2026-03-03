<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('crop_cycle_id');
            $table->uuid('land_parcel_id');
            $table->uuid('tenant_crop_item_id');
            $table->string('name', 255)->nullable();
            $table->decimal('area', 12, 4)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels')->onDelete('cascade');
            $table->foreign('tenant_crop_item_id')->references('id')->on('tenant_crop_items')->onDelete('restrict');
            $table->index(['tenant_id']);
            $table->index(['crop_cycle_id']);
            $table->index(['land_parcel_id']);
        });

        DB::statement('ALTER TABLE field_blocks ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_blocks');
    }
};
