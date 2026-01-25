<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            CREATE TYPE inv_stock_movement_type AS ENUM ('GRN', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUST');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('inv_stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->string('movement_type')->nullable(false);
            $table->uuid('store_id')->nullable(false);
            $table->uuid('item_id')->nullable(false);
            $table->decimal('qty_delta', 18, 6)->nullable(false);
            $table->decimal('value_delta', 18, 2)->nullable(false);
            $table->decimal('unit_cost_snapshot', 18, 6)->nullable(false);
            $table->timestampTz('occurred_at')->nullable(false);
            $table->string('source_type')->nullable(false);
            $table->uuid('source_id')->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->foreign('item_id')->references('id')->on('inv_items');
            $table->index(['tenant_id', 'store_id', 'item_id', 'occurred_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id']);
        });

        DB::statement('ALTER TABLE inv_stock_movements ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE inv_stock_movements DROP COLUMN movement_type');
        DB::statement("ALTER TABLE inv_stock_movements ADD COLUMN movement_type inv_stock_movement_type NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_movements');
        DB::statement('DROP TYPE IF EXISTS inv_stock_movement_type');
    }
};
