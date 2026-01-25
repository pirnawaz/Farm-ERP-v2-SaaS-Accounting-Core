<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make crop_cycle_id nullable so INVENTORY_GRN (and similar) can create
     * posting groups without a crop cycle.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE posting_groups ALTER COLUMN crop_cycle_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Ensure no nulls before reverting
        DB::statement("UPDATE posting_groups SET crop_cycle_id = (SELECT id FROM crop_cycles WHERE crop_cycles.tenant_id = posting_groups.tenant_id LIMIT 1) WHERE crop_cycle_id IS NULL");
        DB::statement('ALTER TABLE posting_groups ALTER COLUMN crop_cycle_id SET NOT NULL');
    }
};
