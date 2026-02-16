<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add JOURNAL_ENTRY to posting_group_source_type for manual GL journal postings.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'JOURNAL_ENTRY'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values easily; no-op.
    }
};
