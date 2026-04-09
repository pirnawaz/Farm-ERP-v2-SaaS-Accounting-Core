<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'FIXED_ASSET_DEPRECIATION_RUN'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'FIXED_ASSET_DEPRECIATION'");
    }

    public function down(): void
    {
        // PostgreSQL: cannot remove enum values safely.
    }
};
