<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'HARVEST_IN_KIND_MACHINE'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'HARVEST_IN_KIND_LABOUR'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'HARVEST_IN_KIND_LANDLORD'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'HARVEST_IN_KIND_CONTRACTOR'");
    }

    public function down(): void
    {
        // Postgres: cannot remove enum values safely; no-op
    }
};
