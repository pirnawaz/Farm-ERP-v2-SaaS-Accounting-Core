<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'SUPPLIER_PAYMENT'");
    }

    public function down(): void
    {
        // Enum values cannot be removed safely in PostgreSQL.
    }
};

