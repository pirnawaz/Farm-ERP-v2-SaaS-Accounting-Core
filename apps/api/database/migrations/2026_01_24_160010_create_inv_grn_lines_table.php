<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_grn_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('grn_id')->nullable(false);
            $table->uuid('item_id')->nullable(false);
            $table->decimal('qty', 18, 6)->nullable(false);
            $table->decimal('unit_cost', 18, 6)->nullable(false);
            $table->decimal('line_total', 18, 2)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('grn_id')->references('id')->on('inv_grns')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inv_items');
            $table->index(['tenant_id']);
            $table->index(['grn_id']);
        });

        DB::statement('ALTER TABLE inv_grn_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_grn_lines');
    }
};
