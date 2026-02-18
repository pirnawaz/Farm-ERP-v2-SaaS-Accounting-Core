<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add PERIOD_CLOSE to posting_group_source_type and allocation_row_allocation_type
     * for crop cycle closing entries (retained earnings roll-forward).
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'PERIOD_CLOSE'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'PERIOD_CLOSE'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values easily; no-op.
    }
};
