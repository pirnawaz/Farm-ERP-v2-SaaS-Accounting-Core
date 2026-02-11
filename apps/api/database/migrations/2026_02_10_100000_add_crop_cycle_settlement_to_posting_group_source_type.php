<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add CROP_CYCLE_SETTLEMENT to posting_group_source_type enum.
     * SettlementService creates posting_groups with source_type = 'CROP_CYCLE_SETTLEMENT'.
     * Idempotent and safe for production: no existing data is modified.
     */
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'CROP_CYCLE_SETTLEMENT';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values. No-op.
    }
};
