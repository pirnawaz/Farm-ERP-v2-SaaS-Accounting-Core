<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'archived' to tenant_status enum for tenant lifecycle.
     */
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }
        DB::statement("DO $$ BEGIN
            ALTER TYPE tenant_status ADD VALUE 'archived';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing a value from an enum easily; leave as-is.
    }
};
