<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add ADVANCE to posting_group_source_type enum so AdvanceService can create posting groups.
     */
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'ADVANCE';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing values from enums; would require recreate.
        // No-op for safety.
    }
};
