<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('transfer_id')->nullable(false);
            $table->uuid('item_id')->nullable(false);
            $table->decimal('qty', 18, 6)->nullable(false);
            $table->decimal('unit_cost_snapshot', 18, 6)->nullable();
            $table->decimal('line_total', 18, 2)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('transfer_id')->references('id')->on('inv_transfers')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inv_items');
            $table->index(['tenant_id']);
            $table->index(['transfer_id']);
        });

        DB::statement('ALTER TABLE inv_transfer_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_transfer_lines');
    }
};
