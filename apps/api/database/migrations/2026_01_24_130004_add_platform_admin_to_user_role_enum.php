<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'platform_admin'");
    }

    public function down(): void
    {
        // Postgres does not support removing a value from an enum.
        // A full migration would require recreating the type and column.
        // For rollback we no-op; document that manual intervention may be needed.
    }
};
