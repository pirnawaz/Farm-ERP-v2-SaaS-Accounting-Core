<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add ACCOUNTING_CORRECTION_REVERSAL and ACCOUNTING_CORRECTION to posting_group_source_type
     * for the fix-settlement-postings automation.
     */
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'ACCOUNTING_CORRECTION_REVERSAL';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'ACCOUNTING_CORRECTION';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing values from enums.
    }
};