<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // NOTE: Rollback limitation: PostgreSQL enums cannot easily remove values.
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'SUPPLIER_INVOICE_CREDIT_PREMIUM'");
    }

    public function down(): void
    {
        // Cannot remove enum value easily in PostgreSQL
    }
};

