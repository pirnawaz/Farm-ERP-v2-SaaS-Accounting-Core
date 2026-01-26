<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'HARVEST';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing values from enums. No-op.
    }
};
