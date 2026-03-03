<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add LANDLORD_ONLY to machine_work_log_pool_scope enum (My farm only).
     * Default remains SHARED.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE machine_work_log_pool_scope ADD VALUE IF NOT EXISTS 'LANDLORD_ONLY'");
    }

    /**
     * Postgres does not support removing enum values easily; leave as-is.
     */
    public function down(): void
    {
        // No-op: removing enum value would require recreating type and column
    }
};
