<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename columns
        DB::statement('ALTER TABLE ledger_entries RENAME COLUMN debit TO debit_amount');
        DB::statement('ALTER TABLE ledger_entries RENAME COLUMN credit TO credit_amount');

        // Update both to numeric(12,2) if not already
        DB::statement('ALTER TABLE ledger_entries ALTER COLUMN debit_amount TYPE NUMERIC(12,2)');
        DB::statement('ALTER TABLE ledger_entries ALTER COLUMN credit_amount TYPE NUMERIC(12,2)');

        // Update existing constraints to use new column names
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_debit_check');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_credit_check');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_debit_credit_exclusive');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_debit_credit_required');

        // Recreate constraints with new column names
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_amount_check CHECK (debit_amount >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_credit_amount_check CHECK (credit_amount >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_credit_exclusive CHECK (NOT (debit_amount > 0 AND credit_amount > 0))');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_credit_required CHECK ((debit_amount > 0) OR (credit_amount > 0))');
    }

    public function down(): void
    {
        // Drop constraints
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_debit_amount_check');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_credit_amount_check');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_debit_credit_exclusive');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_debit_credit_required');

        // Rename columns back
        DB::statement('ALTER TABLE ledger_entries RENAME COLUMN debit_amount TO debit');
        DB::statement('ALTER TABLE ledger_entries RENAME COLUMN credit_amount TO credit');

        // Recreate original constraints
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_check CHECK (debit >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_credit_check CHECK (credit >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_credit_exclusive CHECK (NOT (debit > 0 AND credit > 0))');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_debit_credit_required CHECK ((debit > 0) OR (credit > 0))');
    }
};
