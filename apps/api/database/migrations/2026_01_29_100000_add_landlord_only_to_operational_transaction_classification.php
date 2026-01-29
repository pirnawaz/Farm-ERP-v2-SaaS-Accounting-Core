<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add LANDLORD_ONLY to existing enum (PostgreSQL)
        DB::statement("ALTER TYPE operational_transaction_classification ADD VALUE IF NOT EXISTS 'LANDLORD_ONLY'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values easily without recreating the type.
        // Leave enum as-is on rollback; LANDLORD_ONLY will remain but be unused.
    }
};
