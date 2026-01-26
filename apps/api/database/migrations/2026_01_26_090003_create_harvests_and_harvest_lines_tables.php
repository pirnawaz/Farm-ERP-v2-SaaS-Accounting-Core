<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harvests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('harvest_no')->nullable();
            $table->uuid('crop_cycle_id')->nullable(false);
            $table->uuid('land_parcel_id')->nullable();
            $table->date('harvest_date')->nullable(false);
            $table->date('posting_date')->nullable();
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('reversal_posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id', 'crop_cycle_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE harvests ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE harvests ADD CONSTRAINT harvests_status_check CHECK (status IN ('DRAFT', 'POSTED', 'REVERSED'))");

        Schema::create('harvest_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('harvest_id')->nullable(false);
            $table->uuid('inventory_item_id')->nullable(false);
            $table->uuid('store_id')->nullable(false);
            $table->decimal('quantity', 18, 3)->nullable(false);
            $table->string('uom')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('harvest_id')->references('id')->on('harvests')->onDelete('cascade');
            $table->foreign('inventory_item_id')->references('id')->on('inv_items');
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->index(['harvest_id']);
            $table->index(['tenant_id', 'inventory_item_id']);
        });

        DB::statement('ALTER TABLE harvest_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE harvest_lines ADD CONSTRAINT harvest_lines_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('harvest_lines');
        Schema::dropIfExists('harvests');
    }
};
