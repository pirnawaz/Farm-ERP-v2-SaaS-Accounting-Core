<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add PARTY_CONTROL_CONSOLIDATION to allocation_row_allocation_type enum
     * for consolidation command AllocationRows.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'PARTY_CONTROL_CONSOLIDATION'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values; no-op
    }
};
