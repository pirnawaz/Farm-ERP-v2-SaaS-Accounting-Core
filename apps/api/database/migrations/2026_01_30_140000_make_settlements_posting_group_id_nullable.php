<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow posting_group_id to be null for DRAFT settlements (set when posted).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE settlements ALTER COLUMN posting_group_id DROP NOT NULL');
    }

    /**
     * Revert: make posting_group_id NOT NULL again.
     */
    public function down(): void
    {
        DB::statement('UPDATE settlements SET posting_group_id = (SELECT id FROM posting_groups LIMIT 1) WHERE posting_group_id IS NULL');
        DB::statement('ALTER TABLE settlements ALTER COLUMN posting_group_id SET NOT NULL');
    }
};
