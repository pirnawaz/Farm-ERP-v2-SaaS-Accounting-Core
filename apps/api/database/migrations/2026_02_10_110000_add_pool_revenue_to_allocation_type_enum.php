<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add POOL_REVENUE to allocation_row_allocation_type so shared income can be
     * distinguished from shared costs (POOL_SHARE) in settlement profit calculation.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'POOL_REVENUE'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values. No-op.
    }
};
