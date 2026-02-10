<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow negative amount on allocation_rows for ACCOUNTING_CORRECTION reclass rows
     * (-A SHARED, +A party_only). Settlement sums by scope so net effect is correct.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE allocation_rows DROP CONSTRAINT IF EXISTS allocation_rows_amount_check');
        DB::statement('ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_check CHECK (amount IS NULL OR (amount >= -999999999.99 AND amount <= 999999999.99))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE allocation_rows DROP CONSTRAINT IF EXISTS allocation_rows_amount_check');
        DB::statement('ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_check CHECK (amount IS NULL OR amount >= 0)');
    }
};
