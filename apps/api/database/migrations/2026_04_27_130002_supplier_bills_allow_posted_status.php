<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE supplier_bills DROP CONSTRAINT IF EXISTS supplier_bills_status_check');
        DB::statement("ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_status_check CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED', 'POSTED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_bills DROP CONSTRAINT IF EXISTS supplier_bills_status_check');
        DB::statement("ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_status_check CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED'))");
    }
};

