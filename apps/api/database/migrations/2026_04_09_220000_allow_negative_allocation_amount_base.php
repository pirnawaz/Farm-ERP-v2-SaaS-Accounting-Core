<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reversal rows negate allocation amounts; amount_base must allow negatives like amount.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE allocation_rows DROP CONSTRAINT IF EXISTS allocation_rows_amount_base_nonneg');
        DB::statement('ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_base_check CHECK (amount_base IS NULL OR (amount_base >= -999999999.99 AND amount_base <= 999999999.99))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE allocation_rows DROP CONSTRAINT IF EXISTS allocation_rows_amount_base_check');
        DB::statement('ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_base_nonneg CHECK (amount_base IS NULL OR amount_base >= 0)');
    }
};
