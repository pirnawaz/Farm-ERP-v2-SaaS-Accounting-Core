<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_activity_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('activity_id')->nullable(false);
            $table->uuid('store_id')->nullable(false);
            $table->uuid('item_id')->nullable(false);
            $table->decimal('qty', 18, 6)->nullable(false);
            $table->decimal('unit_cost_snapshot', 18, 6)->nullable();
            $table->decimal('line_total', 18, 2)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('activity_id')->references('id')->on('crop_activities')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->foreign('item_id')->references('id')->on('inv_items');
            $table->index(['tenant_id', 'activity_id']);
        });

        DB::statement('ALTER TABLE crop_activity_inputs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE crop_activity_inputs ADD CONSTRAINT crop_activity_inputs_qty_positive CHECK (qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_activity_inputs');
    }
};
