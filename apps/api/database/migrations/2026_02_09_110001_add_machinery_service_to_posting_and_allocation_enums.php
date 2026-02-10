<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add MACHINERY_SERVICE to posting_group_source_type and allocation_row_allocation_type
     * for internal machinery service postings.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'MACHINERY_SERVICE'");
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'MACHINERY_SERVICE';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values. No-op.
    }
};
