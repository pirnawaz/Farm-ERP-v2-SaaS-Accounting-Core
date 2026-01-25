<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add INVENTORY_GRN and INVENTORY_ISSUE to posting_group_source_type enum
     * for inventory module posting.
     */
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'INVENTORY_GRN';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'INVENTORY_ISSUE';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing values from enums.
        // No-op for safety.
    }
};
