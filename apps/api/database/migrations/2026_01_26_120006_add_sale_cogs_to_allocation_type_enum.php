<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'SALE_COGS';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing values from enums. No-op.
    }
};
