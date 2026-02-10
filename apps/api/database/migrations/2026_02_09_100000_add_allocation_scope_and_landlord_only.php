<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add allocation_scope to allocation_rows (SHARED | HARI_ONLY | LANDLORD_ONLY).
     * Add LANDLORD_ONLY to allocation_row_allocation_type enum.
     * Existing rows keep allocation_scope NULL (settlement treats as SHARED).
     */
    public function up(): void
    {
        // Create enum type for allocation_scope (expense burden scope for settlement)
        DB::statement("
            DO $$ BEGIN
                CREATE TYPE allocation_row_allocation_scope AS ENUM ('SHARED', 'HARI_ONLY', 'LANDLORD_ONLY');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END $$
        ");

        if (! Schema::hasColumn('allocation_rows', 'allocation_scope')) {
            DB::statement("ALTER TABLE allocation_rows ADD COLUMN allocation_scope allocation_row_allocation_scope NULL");
        }

        // Add LANDLORD_ONLY to allocation_type enum for party-only landlord expense
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'LANDLORD_ONLY'");
    }

    public function down(): void
    {
        if (Schema::hasColumn('allocation_rows', 'allocation_scope')) {
            Schema::table('allocation_rows', function (Blueprint $table) {
                $table->dropColumn('allocation_scope');
            });
        }
        DB::statement('DROP TYPE IF EXISTS allocation_row_allocation_scope');
        // PostgreSQL does not support removing enum values; LANDLORD_ONLY remains in allocation_row_allocation_type
    }
};
