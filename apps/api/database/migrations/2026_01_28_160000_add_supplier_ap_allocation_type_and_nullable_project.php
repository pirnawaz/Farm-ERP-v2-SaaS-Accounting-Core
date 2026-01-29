<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'SUPPLIER_AP'");
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN project_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN project_id SET NOT NULL');
        // Cannot remove enum value easily in PostgreSQL
    }
};
